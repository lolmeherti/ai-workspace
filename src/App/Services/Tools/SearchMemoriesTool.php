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
        $query = $params['query'] ?? '';
        if (empty($query)) {
            return "Error: Missing 'query' parameter for search_memories tool.";
        }

        $selector = new MemorySelector($this->db);
        $results = $selector->selectRelevantMemory($query);

        if (!$results) {
            return "No specific relevant memories found for this query in the long-term database.";
        }

        return "Found the following relevant memories:\n\n" . $results;
    }
}
