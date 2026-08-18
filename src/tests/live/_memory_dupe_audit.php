<?php
declare(strict_types=1);
// STRUCTURAL audit of the memories table — prints counts and hashes ONLY.
// Never prints memory_text (user does not wish the values accessed).
require dirname(__DIR__, 2) . '/vendor/autoload.php';
\App\Config::load(dirname(__DIR__, 2));
$db = new \App\Database();

$total = (int)$db->query("SELECT COUNT(*) c FROM memories")[0]['c'];
$distinct = (int)$db->query("SELECT COUNT(DISTINCT memory_text) c FROM memories")[0]['c'];
echo "total_rows=$total distinct_rows=$distinct exact_dup_rows=" . ($total - $distinct) . "\n";

echo "--- exact-duplicate clusters (MD5 -> row count) ---\n";
$clusters = $db->query(
    "SELECT MD5(memory_text) h, COUNT(*) c, MIN(id) first_id, MAX(id) last_id
     FROM memories GROUP BY h HAVING c > 1 ORDER BY c DESC"
);
foreach ($clusters as $r) {
    echo "hash=" . substr($r['h'], 0, 12) . " rows=" . $r['c'] . " id_range=" . $r['first_id'] . "-" . $r['last_id'] . "\n";
}

echo "--- format heuristic: rows whose text contains ':' (key:value style) vs declarative ---\n";
$kv = (int)$db->query("SELECT COUNT(*) c FROM memories WHERE memory_text LIKE '%:%'")[0]['c'];
echo "key_value_style_rows=$kv declarative_rows=" . ($total - $kv) . "\n";
