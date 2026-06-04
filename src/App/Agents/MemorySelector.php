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
        $limit    = (int) Config::get('MAX_MEMORIES_LIMIT', 500);
        $memories = $this->db->getConnection()->query("SELECT id, memory_text FROM memories ORDER BY id DESC LIMIT {$limit}")->fetchAll();

        if (empty($memories)) {
            return null;
        }

        $memoryContext = "";
        foreach ($memories as $memory) {
            $memoryContext .= "ID: {$memory['id']} | Memory: {$memory['memory_text']}\n";
        }

        $systemPrompt = "You are a memory selection agent. You will be provided with a list of user memories and a current user prompt. Your ONLY job is to return the numeric ID of the memory that is highly relevant to answering the user's prompt. If no memory is highly relevant, reply exactly with the word: NONE. Do not include any other words, punctuation, or explanations.";

        $userMessage = "Memories:\n" . $memoryContext . "\n\nCurrent User Prompt:\n" . $userPrompt;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_MEMORY_SELECTOR_TEMP', 0.3);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strtoupper($response) === 'NONE' || !is_numeric($response)) {
            return null;
        }

        $selectedMemoryId = (int) $response;

        foreach ($memories as $memory) {
            if ((int) $memory['id'] === $selectedMemoryId) {
                return $memory['memory_text'];
            }
        }

        return null;
    }
}