<?php
require '/var/www/html/vendor/autoload.php';
\App\Config::load('/var/www/html');
$db = new \App\Database();

$rowId = 4076;
$row = $db->query("SELECT search_query, selected_chunks, backing_chunks FROM chat_history WHERE id = ?", [$rowId]);
if (empty($row)) { echo "row not found\n"; exit; }
$row = $row[0];

$chunks = \App\ChatManager::decodeChunks((string)($row['selected_chunks'] ?? ''));
if (empty($chunks)) $chunks = \App\ChatManager::decodeChunks((string)($row['backing_chunks'] ?? ''));
echo "chunks: " . count($chunks) . "\n";
echo "query: " . ($row['search_query'] ?? '') . "\n";

$agent = new \App\AgentManager();
$condenser = new \App\Search\SourceCondenser($agent);

$t0 = microtime(true);
$claims = $condenser->condenseBatched($chunks, (string)($row['search_query'] ?? ''));
$elapsed = round((microtime(true) - $t0) * 1000);

echo "elapsed: {$elapsed}ms\n";
echo "claims: " . count($claims) . "\n";
foreach (array_slice($claims, 0, 8) as $c) {
    echo "  [{$c['source_id']}] {$c['claim']}\n";
}
echo "atom lines rendered:\n" . \App\Services\PromptAssemblyService::renderAtomLines($claims) . "\n";
