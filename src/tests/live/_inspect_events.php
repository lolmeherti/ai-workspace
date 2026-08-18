<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
\App\Config::load(dirname(__DIR__, 2));
$db = new \App\Database();
$rows = $db->query("SELECT event_type, COUNT(*) AS c FROM app_events WHERE event_type IN ('atomization_backlog','consolidation_ok','consolidation_empty','consolidation_failed') GROUP BY event_type");
foreach ($rows as $r) {
    echo $r['event_type'] . ' = ' . $r['c'] . "\n";
}
echo "--- recent (last 14, chronological) ---\n";
$rows = $db->query("SELECT event_type, message, created_at FROM app_events WHERE event_type LIKE 'consolidation%' OR event_type='atomization_backlog' ORDER BY id DESC LIMIT 14");
foreach (array_reverse($rows) as $r) {
    echo $r['created_at'] . ' | ' . $r['event_type'] . ' | ' . $r['message'] . "\n";
}
