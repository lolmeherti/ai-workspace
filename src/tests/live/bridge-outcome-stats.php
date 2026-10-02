<?php

declare(strict_types=1);

/*
 * Bridge fetch outcome stats (read-only).
 *
 * Answers "how often does browsing succeed vs get blocked": BridgeFetchLogger
 * classifies every fetch (ok / empty / blocked / cooldown / timeout / http_error
 * / rejected / extract_error / disconnected / busy) and records it as a
 * `bridge_fetch` event in app_events. This tallies those outcomes and the
 * domains behind them, so a browsing change can be measured instead of guessed.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/bridge-outcome-stats.php [days]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

$db = new Database();
$days = max(1, (int) ($argv[1] ?? 7));

echo "bridge fetch outcome stats (last {$days} days)\n";

$rows = $db->query("SELECT event_type, COUNT(*) AS n FROM app_events
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY event_type ORDER BY n DESC LIMIT 25");
echo "\ntop event types:\n";
foreach ($rows as $row) {
    printf("  %-32s %6d\n", (string) $row['event_type'], (int) $row['n']);
}

$totalRows = $db->query("SELECT COUNT(*) AS n FROM app_events
    WHERE event_type = 'bridge_fetch' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
$total = (int) ($totalRows[0]['n'] ?? 0);
echo "\nbridge_fetch events: {$total}\n";
if ($total === 0) {
    echo "no classified fetches recorded in this window\n";
    exit(0);
}

$sample = $db->query("SELECT context FROM app_events WHERE event_type = 'bridge_fetch' LIMIT 1");
echo 'context keys: ' . implode(', ', array_keys(json_decode((string) ($sample[0]['context'] ?? '{}'), true) ?: [])) . "\n";

$statuses = $db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(context, '$.status')) AS status, COUNT(*) AS n
    FROM app_events WHERE event_type = 'bridge_fetch'
      AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY status ORDER BY n DESC");
echo "\nby status:\n";
foreach ($statuses as $row) {
    $n = (int) $row['n'];
    printf("  %-18s %5d  %5.1f%%\n", (string) ($row['status'] ?? '?'), $n, $n / $total * 100);
}

// The outcome label is the quality signal: 'ok' = fetched AND extracted usable
// content, 'empty' = fetched but nothing usable, 'blocked' = challenge/consent.
$outcomes = $db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(context, '$.outcome')) AS outcome, COUNT(*) AS n
    FROM app_events WHERE event_type = 'bridge_fetch'
      AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY outcome ORDER BY n DESC");
echo "\nby outcome:\n";
foreach ($outcomes as $row) {
    $n = (int) $row['n'];
    printf("  %-18s %5d  %5.1f%%\n", (string) ($row['outcome'] ?? '?'), $n, $n / $total * 100);
}

$entities = $db->query("SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.entity_count')) AS UNSIGNED) AS entities, COUNT(*) AS n
    FROM app_events WHERE event_type = 'bridge_fetch'
      AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY entities ORDER BY entities ASC LIMIT 12");
echo "\nentity_count (0 = we got the page, extracted nothing usable):\n";
foreach ($entities as $row) {
    printf("  entities=%-5s %5d\n", (string) $row['entities'], (int) $row['n']);
}

$domains = $db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(context, '$.domain')) AS domain,
       JSON_UNQUOTE(JSON_EXTRACT(context, '$.status')) AS status, COUNT(*) AS n
    FROM app_events WHERE event_type = 'bridge_fetch'
      AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY domain, status ORDER BY n DESC LIMIT 15");
echo "\ntop domain / status pairs:\n";
foreach ($domains as $row) {
    printf("  %-30s %-16s %4d\n", (string) ($row['domain'] ?? '?'), (string) ($row['status'] ?? '?'), (int) $row['n']);
}
