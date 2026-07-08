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

        $allResults = [];
        foreach ($queries as $q) {
            $selector = new MemorySelector($this->db);
            $results = $selector->selectRelevantMemory($q);
            $allResults[] = $results ?: '';
        }

        $nonEmpty = array_filter($allResults);
        if (empty($nonEmpty)) {
            return "No specific relevant memories found for this query in the long-term database.";
        }

        if (count($queries) === 1) {
            return "Found the following relevant memories:\n\n" . $allResults[0];
        }

        $combined = SearchWebTool::combineResults($queries, $allResults, 'Memory');
        return "Found the following relevant memories:\n\n" . $combined;
    }
}
