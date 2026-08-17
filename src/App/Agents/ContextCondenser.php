<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;
use App\Database;
use Exception;

class ContextCondenser
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function generateCondensationPreview(Database $db, int $sessionId, bool $manual = false): array
    {
        $history = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
        
        $keepLimit = $manual ? 2 : (int) Config::get('CONDENSATION_KEEP_LIMIT', 6);
        $cutoffIndex = $this->findCutoffIndex($history, $keepLimit);
        if ($cutoffIndex === -1) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        $archive = $this->condenseArchive($history, $cutoffIndex);
        if (empty($archive)) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        $archiveText = "";
        foreach ($archive as $msg) {
            $archiveText .= ucfirst($msg['role']) . ": " . $msg['message'] . "\n";
        }

        $systemPrompt = "You are a highly efficient context management assistant. Your task is to analyze the conversation archive of an AI assistant and a user.\n" .
            "You must return ONLY a JSON object matching this schema:\n" .
            "{\n" .
            "  \"summary\": \"A concise, clear summary of the discussion, decisions, and context up to this point.\",\n" .
            "  \"memories\": [\"factual bullet point 1 about the user\", \"factual bullet point 2 about the user\"]\n" .
            "}\n" .
            "Do not return any conversational text, explanations, or markdown boxes. Output strictly valid JSON.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "CONVERSATION ARCHIVE TO CONDENSE:\n" . $archiveText]
        ];

        $temperature = (float) Config::get('AGENT_CONDENSER_TEMP', 0.4);
        $response = trim($this->agent->chat($messages, false, null, $temperature));
        $data = \App\JsonParser::extractAndDecode($response);

        if (!$data || !isset($data['summary'])) {
            throw new Exception("Condensation preview failed: Invalid or non-JSON response from LLM.");
        }

        return [
            'status' => 'success',
            'summary' => trim($data['summary']),
            'memories' => $data['memories'] ?? []
        ];
    }

    public function commitCondensation(Database $db, int $sessionId, string $summary, array $selectedMemories, bool $manual = false): array
    {
        $history = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
        
        $keepLimit = $manual ? 2 : (int) Config::get('CONDENSATION_KEEP_LIMIT', 6);
        $cutoffIndex = $this->findCutoffIndex($history, $keepLimit);
        if ($cutoffIndex === -1) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        $archive = $this->condenseArchive($history, $cutoffIndex);
        if (empty($archive)) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }
        $archiveIds = array_column($archive, 'id');
        $oldestId = min($archiveIds);
        
        $formattedSummary = "SUMMARY OF PREVIOUS CONVERSATION:\n" . $summary;
        
        $db->query("START TRANSACTION");

        try {
            $db->update('chat_history', [
                'role' => 'assistant',
                'message' => $formattedSummary,
                'message_type' => 'condensation_summary',
                'image_path' => null,
                'token_estimate' => (int)(mb_strlen($formattedSummary) / 4),
                'search_query' => null,
            ], ['id' => $oldestId]);

            $deleteIds = array_filter($archiveIds, fn($id) => $id !== $oldestId);
            if (!empty($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $db->query("DELETE FROM chat_history WHERE id IN ($placeholders)", array_values($deleteIds));
            }

            $maxLimit = (int) Config::get('MAX_MEMORIES_LIMIT', 500);
            foreach ($selectedMemories as $memoryText) {
                $memoryText = trim($memoryText, " \t\n\r\0\x0B-*•");
                if ($memoryText === '') {
                    continue;
                }

                $countResult = $db->query("SELECT COUNT(*) as count FROM memories");
                $count = (int)($countResult[0]['count'] ?? 0);
                
                if ($count >= $maxLimit) {
                    $db->query("DELETE FROM memories ORDER BY id ASC LIMIT 1");
                }

                $db->insert('memories', [
                    'memory_text' => $memoryText
                ]);
            }

            $db->query("COMMIT");
            $db->update('chat_sessions', ['context_tokens' => 0], ['id' => $sessionId]);
        } catch (Exception $e) {
            $db->query("ROLLBACK");
            throw $e;
        }

        return [
            'status' => 'success',
            'summary' => $summary,
            'memories_count' => count($selectedMemories)
        ];
    }

    /**
     * Conversation rows before the cutoff that are eligible for condensation.
     * data_fetching (external evidence) is excluded — it survives untouched.
     *
     * @return array<int, array<string, mixed>>
     */
    private function condenseArchive(array $history, int $cutoffIndex): array
    {
        $archive = [];
        foreach ($history as $i => $msg) {
            if ($i >= $cutoffIndex) {
                break;
            }
            if (($msg['message_type'] ?? '') === 'data_fetching') {
                continue;
            }
            $archive[] = $msg;
        }
        return $archive;
    }

    private function findCutoffIndex(array $history, int $keepLimit): int
    {
        $uaCount = 0;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $role = $history[$i]['role'] ?? '';
            if ($role === 'user' || $role === 'assistant') {
                $uaCount++;
                if ($uaCount >= $keepLimit) {
                    return $i;
                }
            }
        }
        return -1;
    }
    
}
