<?php

declare(strict_types=1);

/*
 * Regression check for items 14/15: the Reasoning control resolves its levels from the launcher's
 * runtime policy. A settings save used to round-trip that JSON through a one-line text field, and a
 * mangled write silently degraded the control to Off/On. This reports the state the control depends
 * on — validity and structure, never the values.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/runtime-policy-integrity-check.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;

Config::load(dirname(__DIR__, 2));

// Report *where* the value came from: an earlier version of this check guessed its own .env path
// and read a stale file while the app read another, so it reported "no effort_map" against a
// perfectly good policy. Anything that inspects configuration must name its source.
echo 'project root: ' . Config::getProjectRoot() . "\n";
$envFile = Config::getProjectRoot() . '/.env';
echo '.env: ' . $envFile . (is_file($envFile) ? ' (mtime ' . date('H:i:s', (int) filemtime($envFile)) . ')' : ' (MISSING)') . "\n";

// phpdotenv does not override variables already present in the process environment, and the web
// container receives the compose env_file at start — so the resolved value can come from the
// container environment while the mounted .env holds something older. Print both, so a mismatch is
// visible instead of silently misleading (this check once read the file while the app read the env).
$fileRaw = '';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with(trim($line), 'LLM_RUNTIME_POLICY=')) {
            $fileRaw = trim(substr(trim($line), strlen('LLM_RUNTIME_POLICY=')), " \t\"'");
            break;
        }
    }
}
$processRaw = (string) getenv('LLM_RUNTIME_POLICY');
foreach (['file' => $fileRaw, 'process env' => $processRaw] as $src => $raw) {
    $keys = ($raw !== '' && is_array($d = json_decode($raw, true))) ? implode(',', array_keys($d)) : '(none)';
    echo sprintf("  source %-11s len=%-5d keys=%s\n", $src, strlen($raw), $keys);
}
echo "\n";

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [OK]   {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

foreach (['LLM_SAMPLING', 'LLM_RUNTIME_POLICY'] as $key) {
    $raw = trim((string) Config::get($key, ''));
    $decoded = json_decode($raw, true);

    $ok = $raw === '' || $decoded !== null;
    check(
        $key . ' is valid JSON (or empty)',
        $ok,
        'len=' . strlen($raw)
            . ($ok ? '' : ' — CORRUPT: ' . json_last_error_msg())
    );

    if ($key === 'LLM_RUNTIME_POLICY' && is_array($decoded)) {
        // Print structure, never values: which keys exist is what decides the control's shape.
        echo '  [info] policy top-level keys: ' . implode(',', array_keys($decoded)) . "\n";
        $levels = (isset($decoded['effort_map']) && is_array($decoded['effort_map']))
            ? implode(',', array_keys($decoded['effort_map']))
            : '';
        // Informational: an empty policy is legitimate for runtimes without graduated levels.
        echo '  [info] policy levels: ' . ($levels !== '' ? $levels : '(none -> control shows the runtime default)') . "\n";
    }
}

echo "\n" . ($fail === 0 ? "PASSED" : "FAILURES: {$fail}") . " ({$pass} checks)\n";
