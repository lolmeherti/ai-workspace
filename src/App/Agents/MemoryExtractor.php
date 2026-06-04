<?php

namespace App\Agents;

use App\Database;
use App\AgentManager;
use App\Config;

class MemoryExtractor
{
    private Database $db;
    private AgentManager $agent;

    public function __construct(Database $db, AgentManager $agent)
    {
        $this->db = $db;
        $this->agent = $agent;
    }

    public function runIfThresholdMet(int $sessionId): void
    {
        $threshold = (int) Config::get('MEMORY_EXTRACTION_THRESHOLD_TOKENS', 15000);
        $charThreshold = $threshold * 4;

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        
        $totalChars = 0;
        $chatText = "";

        foreach ($history as $row) {
            $totalChars += mb_strlen($row['message']);
            $chatText .= ucfirst($row['role']) . ": " . $row['message'] . "\n";
        }

        if ($totalChars >= $charThreshold) {
            $this->extractAndSave($chatText);
            
            $stmt = $this->db->getConnection()->prepare("DELETE FROM chat_history WHERE session_id = :session_id");
            $stmt->execute([':session_id' => $sessionId]);
        }
    }

     private function extractAndSave(string $chatText): void
    {
        $prompt = "You are a data extraction agent. Read the following conversation and extract important facts about the user. Return strictly bullet points. Do not include introductory text.\n\n" . $chatText;

        $messages = [
            ['role' => 'system', 'content' => 'Extract facts as bullet points.'],
            ['role' => 'user', 'content' => $prompt]
        ];

        $temperature = (float) Config::get('AGENT_EXTRACTOR_TEMP', 0.3);
        $extractedMemories = $this->agent->chat($messages, false, null, $temperature);

        if (trim($extractedMemories) !== '') {
            $lines = explode("\n", trim($extractedMemories));
            
            foreach ($lines as $line) {
                $cleanLine = trim($line, " \t\n\r\0\x0B-*•");
                
                if ($cleanLine !== '') {
                    $maxLimit = (int) Config::get('MAX_MEMORIES_LIMIT', 500);
                    $count = $this->db->getConnection()->query("SELECT COUNT(*) FROM memories")->fetchColumn();
                    if ($count >= $maxLimit) {
                        $this->db->getConnection()->exec("DELETE FROM memories ORDER BY id ASC LIMIT 1");
                    }
                    
                    $this->db->insert('memories', [
                        'memory_text' => $cleanLine
                    ]);
                }
            }
        }
    }
}