<?php

namespace App\Agents;

use App\Database;
use App\AgentManager;
use App\Config;
use Exception;

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
        }
    }

    public function extractAndSave(string $chatText, bool $isManual = false): void
    {
        $candidates = [];

        if ($isManual) {
            // Retrieve a larger set of existing memories for thorough manual deduplication
            $candidates = $this->db->query("SELECT id, memory_text FROM memories ORDER BY id DESC LIMIT 200");
        } else {
            $keywordPrompt = <<<TEXT
Analyze the following conversation segment and extract 3 to 5 core keywords or short phrases representing the primary subjects, technologies, or topics discussed.
Return ONLY a comma-separated list of keywords, nothing else.

Conversation:
{$chatText}
TEXT;

            $keywordMessages = [
                ['role' => 'user', 'content' => $keywordPrompt]
            ];

            $keywordsRaw = $this->agent->chat($keywordMessages, false, null, 0.1);
            $keywords = trim($keywordsRaw);

            if (!empty($keywords)) {
                $sql = <<<TEXT
SELECT id, memory_text 
FROM memories 
WHERE MATCH(memory_text) AGAINST(:keywords IN NATURAL LANGUAGE MODE)
LIMIT 25
TEXT;
                $candidates = $this->db->query($sql, [':keywords' => $keywords]);
            }

            if (count($candidates) < 15) {
                $needed = 15 - count($candidates);
                $excludeIds = array_column($candidates, 'id');
                $excludeClause = "";
                $params = [':limit' => $needed];
                
                if (!empty($excludeIds)) {
                    $placeholders = [];
                    foreach ($excludeIds as $idx => $id) {
                        $placeholders[] = ":ex_" . $idx;
                        $params[":ex_" . $idx] = $id;
                    }
                    $excludeClause = "WHERE id NOT IN (" . implode(",", $placeholders) . ") ";
                }
                
                $sql = "SELECT id, memory_text FROM memories {$excludeClause}ORDER BY id DESC LIMIT :limit";
                $fallbackCandidates = $this->db->query($sql, $params);
                $candidates = array_merge($candidates, $fallbackCandidates);
            }
        }

        $candidatesStr = "";
        foreach ($candidates as $c) {
            $candidatesStr .= "ID: {$c['id']} | {$c['memory_text']}\n";
        }
        if (empty($candidatesStr)) {
            $candidatesStr = "(None)";
        }

        if ($isManual) {
            $systemPrompt = <<<TEXT
You are a memory consolidation agent. Your task is to perform a thorough maintenance, cleanup, and deduplication of the user's existing memory database.

You will be provided with:
1. Candidate Memories: A list of existing user memories.
2. New Conversation (Optional/May be empty): Recent chat history for context.

Your main goal is to identify similar, overlapping, redundant, or repetitive memories in the Candidate Memories list and merge them.
For example:
- "the user has coding knowledge"
- "the user is a developer"
- "this user works as a software engineer"
- "the user does software engineering for work"

These memories are highly redundant. You should:
1. Choose one memory to update (e.g. ID 48) to represent the consolidated fact: "The user works as a software engineer and has coding knowledge."
2. Delete the other redundant ones (IDs 50, 49, 46) by listing them in "deletions".

Guidelines:
- Consolidate highly similar entries to minimize overall memory count while keeping all unique factual details.
- Output changes matching this schema:
   - UPDATE: Provide the ID of the memory you want to keep and its new, consolidated/expanded declarative sentence.
   - DELETE: List the IDs of all redundant or obsolete memories in "deletions".
   - ADDITION: Add a new durable fact in "additions" ONLY if there is any new information in the conversation that is completely missing from the existing memories.
- Format each memory as a clean, third-person declarative sentence. Do not use bullets or markdown formatting inside the strings.

You MUST return your response strictly as a JSON object matching this schema:
{
  "updates": [
    {"id": 48, "text": "The user works as a software engineer and has coding knowledge"}
  ],
  "deletions": [50, 49, 46],
  "additions": []
}
TEXT;

            $userPrompt = <<<TEXT
Candidate Memories:
{$candidatesStr}

New Conversation:
{$chatText}

Please clean up and deduplicate the candidate memories. Merge overlapping or redundant facts, and return the updates, deletions, and additions as a single valid JSON object matching the requested schema.
TEXT;
        } else {
            $systemPrompt = <<<TEXT
You are a memory consolidation agent. Your task is to maintain a clean, accurate, and deduplicated database of facts and details about the user.

You will be provided with:
1. Candidate Memories: A list of existing user memories.
2. New Conversation: The recent chat history.

Your goal is to isolate and record only "Durable User State" (long-term preferences, persistent constraints, or unique goals) while discarding "Transient Context" (temporary tasks/immediate discussions) and "General Knowledge" (general facts or obvious deductions).

STUDY THIS CLASSIFICATION PROCESS:
- Conversation: "I usually travel by train, but right now I am driving to a meeting because the weather is bad. Also, did you know trains emit 80% less CO2 than cars?"
- Classification Analysis:
  * "Trains emit 80% less CO2" -> DISCARD (General knowledge, not about the user).
  * "Currently driving to a meeting" -> DISCARD (Transient context/temporary task).
  * "Prefers traveling by train" -> ADD (Durable user preference).

Instructions:
1. Review the new conversation and compare any new durable insights with Candidate Memories.
2. Output changes matching this schema:
   - UPDATE: Provide the ID and corrected text if a candidate memory is directly outdated or expanded.
   - DELETE: List the ID in "deletions" if a candidate memory is contradicted or obsolete.
   - ADDITION: Add a new durable fact in "additions" if it is NOT covered by existing memories.
3. Format each memory as a clean, third-person declarative sentence. Do not use bullets or markdown formatting inside the strings.

You MUST return your response strictly as a JSON object matching this schema:
{
  "updates": [
    {"id": 12, "text": "The updated declarative sentence"}
  ],
  "deletions": [45],
  "additions": [
    "A brand new declarative sentence"
  ]
}
TEXT;

            $userPrompt = <<<TEXT
Candidate Memories:
{$candidatesStr}

New Conversation:
{$chatText}

Please analyze and return the updates, deletions, and additions as a single valid JSON object matching the requested schema.
TEXT;
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_EXTRACTOR_TEMP', 0.5);
        $response = $this->agent->chat($messages, false, null, $temperature);

        $response = trim($response);
        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

        if (is_array($data)) {
            try {
                $this->db->query("START TRANSACTION");

                if (!empty($data['updates']) && is_array($data['updates'])) {
                    foreach ($data['updates'] as $upd) {
                        if (isset($upd['id'], $upd['text'])) {
                            $cleanText = trim($upd['text'], " \t\n\r\0\x0B-*•");
                            if ($cleanText !== '') {
                                $this->db->update('memories', [
                                    'memory_text' => $cleanText
                                ], [
                                    'id' => (int)$upd['id']
                                ]);
                            }
                        }
                    }
                }

                if (!empty($data['deletions']) && is_array($data['deletions'])) {
                    foreach ($data['deletions'] as $delId) {
                        $this->db->query("DELETE FROM memories WHERE id = :id", [
                            ':id' => (int)$delId
                        ]);
                    }
                }

                if (!empty($data['additions']) && is_array($data['additions'])) {
                    $maxLimit = (int) Config::get('MAX_MEMORIES_LIMIT', 500);
                    foreach ($data['additions'] as $addText) {
                        $cleanText = trim($addText, " \t\n\r\0\x0B-*•");
                        if ($cleanText !== '') {
                            $countResult = $this->db->query("SELECT COUNT(*) as count FROM memories");
                            $count = (int)($countResult[0]['count'] ?? 0);
                            
                            if ($count >= $maxLimit) {
                                $this->db->query("DELETE FROM memories ORDER BY id ASC LIMIT 1");
                            }

                            $this->db->insert('memories', [
                                'memory_text' => $cleanText
                            ]);
                        }
                    }
                }

                $this->db->query("COMMIT");
            } catch (Exception $e) {
                $this->db->query("ROLLBACK");
                error_log("MemoryExtractor: Failed to write consolidated memories: " . $e->getMessage());
            }
        } else {
            error_log("MemoryExtractor: Invalid JSON response received from LLM during consolidation. Raw response: " . $response);
        }
    }
}