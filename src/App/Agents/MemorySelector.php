<?php

namespace App\Agents;

use App\Database;
use App\AgentManager;
use App\Config;

class MemorySelector
{
    private Database $db;
    private AgentManager $agent;

    public function __construct(Database $db, AgentManager $agent)
    {
        $this->db = $db;
        $this->agent = $agent;
    }

    public function selectRelevantMemory(string $userPrompt): ?string
    {
        $limit = (int) Config::get('MAX_MEMORIES_LIMIT', 500);

        $stmt = $this->db->getConnection()->prepare("
            SELECT id, memory_text 
            FROM memories 
            WHERE MATCH(memory_text) AGAINST(:prompt IN NATURAL LANGUAGE MODE) 
            LIMIT :limit
        ");
        
        $stmt->bindValue(':prompt', $userPrompt, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $memories = $stmt->fetchAll();

        if (empty($memories)) {
            $stmtFallback = $this->db->getConnection()->prepare("
                SELECT id, memory_text 
                FROM memories 
                ORDER BY id DESC 
                LIMIT :limit
            ");
            $stmtFallback->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmtFallback->execute();
            $memories = $stmtFallback->fetchAll();
        }

        if (empty($memories)) {
            return null;
        }

        $memoryContext = "";
        foreach ($memories as $memory) {
            $memoryContext .= "ID: {$memory['id']} | Memory: {$memory['memory_text']}\n";
        }

        $systemPrompt = <<<TEXT
Return ONLY JSON matching this schema:
{
  "relevant_memory_ids": [integer]
}

You will be provided with a list of past user memories and the current user prompt. Identify the IDs of the memories that are highly relevant to answering the prompt. Return an empty array if no memories are relevant.
TEXT;

        $userMessage = "Memories:\n" . $memoryContext . "\n\nCurrent User Prompt:\n" . $userPrompt;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_MEMORY_SELECTOR_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);
        $selectedIds = [];

        if (is_array($data) && isset($data['relevant_memory_ids']) && is_array($data['relevant_memory_ids'])) {
            $selectedIds = array_map('intval', $data['relevant_memory_ids']);
        }

        if (empty($selectedIds)) {
            return null;
        }

        $selectedMemories = [];
        foreach ($memories as $memory) {
            if (in_array((int)$memory['id'], $selectedIds, true)) {
                $selectedMemories[] = "- " . $memory['memory_text'];
            }
        }

        return !empty($selectedMemories) ? implode("\n", $selectedMemories) : null;
    }
}