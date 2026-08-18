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
     * Match memories using the original retrieval semantics (FULLTEXT boolean
     * OR, then a LIKE %word% fallback) and return them as structured rows so
     * callers can deduplicate by id. No token filtering — broad fuzzy recall
     * is intentional.
     *
     * @return array<int, array{id:int, memory_text:string}>
     */
    public function selectRelevantMemories(string $userPrompt): array
    {
        $cleanPrompt = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $userPrompt);
        $words = array_filter(preg_split('/\s+/', $cleanPrompt));

        if (empty($words)) {
            return [];
        }

        $boolQuery = $this->buildBoolQuery($words);
        $memories = [];

        try {
            $memories = $this->db->query(
                "SELECT id, memory_text FROM memories
                 WHERE MATCH(memory_text) AGAINST(:prompt IN BOOLEAN MODE)
                 LIMIT 50",
                [':prompt' => $boolQuery]
            );
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
                $paramName = ':word_' . $index;
                $conditions[] = "memory_text LIKE " . $paramName;
                $params[$paramName] = '%' . $word . '%';
            }

            if (!empty($conditions)) {
                $memories = $this->db->query(
                    "SELECT id, memory_text FROM memories WHERE "
                    . implode(' OR ', $conditions)
                    . " ORDER BY id DESC LIMIT 20",
                    $params
                );
            }
        }

        return $memories;
    }

    public function selectRelevantMemory(string $userPrompt): ?string
    {
        $rows = $this->selectRelevantMemories($userPrompt);
        if (empty($rows)) {
            return null;
        }

        $selectedMemories = [];
        foreach ($rows as $memory) {
            $selectedMemories[] = '- ' . $memory['memory_text'];
        }

        return implode("\n", $selectedMemories);
    }

    private function buildBoolQuery(array $words): string
    {
        return implode(' ', $words);
    }
}
