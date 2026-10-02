<?php

declare(strict_types=1);

/*
 * Re-runnable diagnostic: the full text of recent critical/error events (message + context,
 * where the context carries the exception and its trace). Used to tell a pre-existing failure
 * apart from a new one and to name the writer that threw.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/db-error-detail.php [count]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));
$db = new Database();

$limit = (int) ($argv[1] ?? 4);

$run = static function (string $sql) use ($db): array {
    foreach (['selectSafe', 'select', 'query'] as $method) {
        if (!method_exists($db, $method)) {
            continue;
        }
        try {
            $res = $db->{$method}($sql);
            if (is_object($res) && method_exists($res, 'fetchAll')) {
                return $res->fetchAll();
            }
            if (is_array($res)) {
                return $res;
            }
        } catch (Throwable $e) {
            // try the next accessor
        }
    }
    return [];
};

$rows = $run("SELECT id, created_at, session_id, event_type, level, LEFT(message,200) m, context c
              FROM app_events WHERE level IN ('critical','error') ORDER BY id DESC LIMIT " . max(1, min(20, $limit)));

foreach ($rows as $r) {
    echo '#', $r['id'], '  ', $r['created_at'], '  session ', (string) $r['session_id'],
        '  [', $r['level'], '/' . $r['event_type'], "]\n";
    echo '  message: ', str_replace(["\n", "\r"], ' ', (string) $r['m']), "\n";
    $ctx = str_replace(["\n", "\r"], ' ', (string) ($r['c'] ?? ''));
    if ($ctx !== '') {
        echo '  context: ', substr($ctx, 0, 700), "\n";
    }
    echo "\n";
}
