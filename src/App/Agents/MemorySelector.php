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

        $systemPrompt = "You are a memory selection agent. You will be provided with a list of user memories and a current user prompt. Your ONLY job is to return a comma-separated list of numeric IDs of the memories that are highly relevant to answering the user's prompt. If no memories are highly relevant, reply exactly with the word: NONE. Do not include any other words, punctuation, or explanations.";

        $userMessage = "Memories:\n" . $memoryContext . "\n\nCurrent User Prompt:\n" . $userPrompt;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_MEMORY_SELECTOR_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strtoupper($response) === 'NONE') {
            return null;
        }

        $selectedIds = array_map('intval', array_filter(explode(',', $response), 'is_numeric'));

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