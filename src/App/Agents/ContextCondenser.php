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
        if (count($history) <= $keepLimit) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        $archive = array_slice($history, 0, -$keepLimit);

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

        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

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
        if (count($history) <= $keepLimit) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        $archive = array_slice($history, 0, -$keepLimit);
        $archiveIds = array_column($archive, 'id');
        $oldestId = min($archiveIds);
        
        $formattedSummary = "SUMMARY OF PREVIOUS CONVERSATION:\n" . $summary;
        
        $db->query("START TRANSACTION");

        try {
            $db->update('chat_history', [
                'role' => 'system',
                'message' => $formattedSummary,
                'image_path' => null,
                'token_estimate' => (int)(mb_strlen($formattedSummary) / 4),
                'search_query' => null,
                'cache_used' => 0,
                'scraped_urls' => null
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

    
    public function condense(array $scrapedPages, string $userPrompt): string
    {
        if (empty($scrapedPages)) {
            return "";
        }

        $summary = "";

        foreach ($scrapedPages as $index => $pageText) {
            if ($index === 0) {
                $summary = $this->initialSummary($pageText, $userPrompt);
            } else {
                $summary = $this->updateSummary($summary, $pageText, $userPrompt);
            }
        }

        return $summary;
    }

    private function initialSummary(string $text, string $userPrompt): string
    {
        $systemPrompt = "You are an assistant organizing context for yourself. Read the provided web text and extract the information most relevant to the user's prompt. Keep your notes concise and factual.";
        
        $userMessage = "User Prompt: " . $userPrompt . "\n\nWeb Text:\n" . $text;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CONDENSER_TEMP', 0.4);
        return $this->agent->chat($messages, false, null, $temperature);
    }

    private function updateSummary(string $currentSummary, string $newText, string $userPrompt): string
    {
        $systemPrompt = "You are an assistant organizing context for yourself. You have existing notes and new web text. Update your notes by incorporating any new, relevant facts from the web text that help answer the user prompt. Discard useless information.";
        
        $userMessage = "User Prompt: " . $userPrompt . "\n\nExisting Notes:\n" . $currentSummary . "\n\nNew Web Text:\n" . $newText;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CONDENSER_TEMP', 0.4);
        return $this->agent->chat($messages, false, null, $temperature);
    }
}