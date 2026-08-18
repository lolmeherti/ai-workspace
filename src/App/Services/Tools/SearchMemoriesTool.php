<?php

namespace App\Services\Tools;

use App\Database;
use App\AgentManager;
use App\Agents\MemorySelector;

class SearchMemoriesTool
{
    private Database $db;
    private AgentManager $agent;

    public function __construct(Database $db, AgentManager $agent)
    {
        $this->db = $db;
        $this->agent = $agent;
    }

    public function execute(array $params, int $sessionId, array $messages, callable $emit, string $originalJson): string
    {
        $rawQuery = $params['query'] ?? '';
        if (empty($rawQuery)) {
            return "Error: Missing 'query' parameter for search_memories tool.";
        }

        $queries = SearchWebTool::splitQueries($rawQuery);
        $totalQueries = count($queries);

        $selector = new MemorySelector($this->db);
        $allRows = [];

        foreach ($queries as $i => $q) {
            if ($totalQueries > 1) {
                \App\ProgressWriter::write($sessionId, 'search_querying', "Search {$i}/{$totalQueries}: {$q}", 'slate');
            }
            foreach ($selector->selectRelevantMemories($q) as $row) {
                $allRows[] = $row;
            }
        }

        // One stored memory appears once, regardless of how many expanded
        // sub-queries matched it.
        $uniqueRows = self::deduplicateRows($allRows);

        if (empty($uniqueRows)) {
            \App\ProgressWriter::write($sessionId, 'search_no_results', "No memories found for: {$rawQuery}", 'rose');
            return "No specific relevant memories found for this query in the long-term database.";
        }

        \App\ProgressWriter::write($sessionId, 'search_done', 'Memories retrieved.', 'emerald');

        $lines = [];
        foreach ($uniqueRows as $row) {
            $lines[] = '- ' . $row['memory_text'];
        }

        return "Found the following relevant memories:\n\n" . implode("\n", $lines);
    }

    /**
     * Deduplicate rows by memory id, preserving first-seen (query) order.
     *
     * @param array<int, array{id:int, memory_text:string}> $rows
     * @return array<int, array{id:int, memory_text:string}>
     */
    public static function deduplicateRows(array $rows): array
    {
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $row;
        }
        return $unique;
    }
}
