<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class ContextCondenser
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Condense the chat history of a session, leaving the last 3 rounds (6 messages) intact.
     * The older messages are summarized and factual memories are extracted from them.
     */
    public function condenseChatHistory(\App\Database $db, int $sessionId): array
    {
        $history = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
        
        if (count($history) <= 6) {
            return ['status' => 'error', 'message' => 'Conversation is too short to condense.'];
        }

        // Split the history: keep the last 6 messages
        $archive = array_slice($history, 0, -6);
        $keep = array_slice($history, -6);

        // Build the text representation of the archived messages to summarize
        $archiveText = "";
        foreach ($archive as $msg) {
            $archiveText .= ucfirst($msg['role']) . ": " . $msg['message'] . "\n";
        }

        // System prompt to extract summary and memories as JSON
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

        // Clean any potential markdown wrappers around JSON
        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['summary'])) {
            throw new \Exception("Condensation failed: Invalid or non-JSON response from LLM.");
        }

        $summaryText = trim($data['summary']);
        $memoriesExtracted = $data['memories'] ?? [];

        // Start transaction
        $pdo = $db->getConnection();
        $pdo->beginTransaction();

        try {
            // Get all IDs of the archive messages
            $archiveIds = array_column($archive, 'id');
            
            // Step 1: Update the very oldest message to act as the Summary message
            $oldestId = min($archiveIds);
            
            $formattedSummary = "SUMMARY OF PREVIOUS CONVERSATION:\n" . $summaryText;
            
            $db->update('chat_history', [
                'role' => 'system',
                'message' => $formattedSummary,
                'image_path' => null,
                'token_estimate' => (int)(mb_strlen($formattedSummary) / 4),
                'search_query' => null,
                'cache_used' => 0,
                'scraped_urls' => null
            ], ['id' => $oldestId]);

            // Step 2: Delete all other messages in the archive (except the oldest one we updated)
            $deleteIds = array_filter($archiveIds, fn($id) => $id !== $oldestId);
            if (!empty($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM chat_history WHERE id IN ($placeholders)");
                $stmt->execute(array_values($deleteIds));
            }

            // Step 3: Insert the extracted memories
            $maxLimit = (int) Config::get('MAX_MEMORIES_LIMIT', 500);
            foreach ($memoriesExtracted as $memoryText) {
                $memoryText = trim($memoryText, " \t\n\r\0\x0B-*•");
                if ($memoryText === '') {
                    continue;
                }

                // Clean-up memories limit if needed
                $count = $pdo->query("SELECT COUNT(*) FROM memories")->fetchColumn();
                if ($count >= $maxLimit) {
                    $pdo->exec("DELETE FROM memories ORDER BY id ASC LIMIT 1");
                }

                $db->insert('memories', [
                    'memory_text' => $memoryText
                ]);
            }

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'status' => 'success',
            'summary' => $summaryText,
            'memories_count' => count($memoriesExtracted)
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