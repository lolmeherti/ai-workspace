<?php
declare(strict_types=1);
// Count-only verification that e2e fixtures were cleaned up. Prints no values.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
\App\Config::load(dirname(__DIR__, 2));
$db = new \App\Database();
foreach (['alphaqzx', 'bravoqzx', 'charlieqzx'] as $p) {
    $n = (int)$db->query("SELECT COUNT(*) c FROM memories WHERE memory_text LIKE :p", [':p' => "%{$p}%"])[0]['c'];
    echo "{$p} remaining={$n}\n";
}
echo 'total_memories=' . (int)$db->query('SELECT COUNT(*) c FROM memories')[0]['c'] . "\n";
