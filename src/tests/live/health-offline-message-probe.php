<?php

declare(strict_types=1);

/*
 * Offline-message probe (live, read-only).
 *
 * Verifies the PHP half of silent-failure task 4: when the AI probe fails, the
 * message must say what was probed and what came back, and the launcher's own
 * engine failure must take precedence — instead of the old "The AI service is
 * offline. Check the launcher.", which names one cause while the real one may be
 * another.
 *
 * Needs the launcher stub (and, for case A, nothing else):
 *   docker exec -d ai_php_web php -S 127.0.0.1:9876 /var/www/html/tests/live/launcher-stub-server.php
 *
 * Case A points LLM_API_URL at a dead port on 127.0.0.1, so the launcher lookup
 * (derived at the same host, :9876) reaches the stub and its failure should win.
 * Case B uses 127.0.0.2, where no launcher stub listens, so the probe detail is
 * what gets reported.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/health-offline-message-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\HealthCheck;

Config::load(dirname(__DIR__, 2));

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$real = (string) Config::get('LLM_API_URL', '');
$check = new HealthCheck();

echo "offline-message probe\n\n";

// Case A: dead AI port, launcher stub answers with a boot failure.
$_ENV['LLM_API_URL'] = 'http://127.0.0.1:9092/v1';
$a = $check->checkAi();
echo "A  dead probe + launcher failure\n";
echo "   online:  " . var_export($a['online'], true) . "\n";
echo "   message: " . ($a['message'] ?? '—') . "\n";
echo "   detail:  " . ($a['detail'] ?? '—') . "\n";
$fromLauncher = ($a['message'] ?? '') === 'The stub engine failed to start (launcher stub)';
printf("   [%s] the launcher's reason won over the probe\n\n", $fromLauncher ? 'OK' : 'FAIL');

// Case B: dead AI port on a host with no launcher, so the probe detail is reported.
$_ENV['LLM_API_URL'] = 'http://127.0.0.2:9093/v1';
$b = $check->checkAi();
echo "B  dead probe, no launcher\n";
echo "   online:  " . var_export($b['online'], true) . "\n";
echo "   message: " . ($b['message'] ?? '—') . "\n";
echo "   detail:  " . ($b['detail'] ?? '—') . "\n";
$mentionsProbe = str_contains((string) ($b['message'] ?? ''), 'offline at http://127.0.0.2:9093/v1')
    && str_contains((string) ($b['detail'] ?? ''), 'no answer from');
printf("   [%s] the message names the endpoint, the detail names what was probed\n\n", $mentionsProbe ? 'OK' : 'FAIL');

// Case C: control — the real endpoint must still report online.
$_ENV['LLM_API_URL'] = $real;
$c = $check->checkAi();
printf("C  control (real endpoint %s): online=%s model=%s\n", $real, var_export($c['online'], true), (string) ($c['model'] ?? '—'));
printf("   [%s] healthy endpoint still reports online\n", ($c['online'] ?? false) ? 'OK' : 'FAIL');
