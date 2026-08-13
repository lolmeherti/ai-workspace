<?php

namespace App\Agents;

use App\Database;
use App\Repositories\MemoryRepository;

class MemorySelector
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Performs direct, database-only memory lookup without triggering secondary LLM calls.
     */
    public function selectRelevantMemory(string $userPrompt): ?string
    {
        $cleanPrompt = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $userPrompt);
        $words = array_filter(preg_split('/\s+/', $cleanPrompt));
        
        if (empty($words)) {
            return null;
        }

        $boolQuery = $this->buildBoolQuery($words);
        $memories = [];

        $sqlFulltext = <<<TEXT
SELECT id, memory_text 
FROM memories 
WHERE MATCH(memory_text) AGAINST(:prompt IN BOOLEAN MODE) 
LIMIT 50
TEXT;

        try {
            $memories = $this->db->query($sqlFulltext, [
                ':prompt' => $boolQuery
            ]);
        } catch (\Throwable $e) {
            $memories = [];
        }

        if (empty($memories)) {
            $conditions = [];
            $params = [];
            foreach (array_values($words) as $index => $word) {
                if (strlen($word) < 2) {
                    continue; 
                }
                $paramName = ":word_" . $index;
                $conditions[] = "memory_text LIKE " . $paramName;
                $params[$paramName] = "%" . $word . "%";
            }

            if (!empty($conditions)) {
                $sqlLike = "SELECT id, memory_text FROM memories WHERE " . implode(" OR ", $conditions) . " ORDER BY id DESC LIMIT 20";
                $memories = $this->db->query($sqlLike, $params);
            }
        }

        if (empty($memories)) {
            return null;
        }

        $selectedMemories = [];
        foreach ($memories as $memory) {
            $selectedMemories[] = "- " . $memory['memory_text'];
        }

        return implode("\n", $selectedMemories);
    }

    private function buildBoolQuery(array $words): string
    {
        return implode(' ', $words);
    }
}