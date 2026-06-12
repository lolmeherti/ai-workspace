<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;

class ChatReplyAssistAction extends BaseAction
{
    public function __construct(private $agentManager)
    {
    }

    public function execute(): void
    {
        $originalSubject = $_POST['original_subject'] ?? '';
        $originalFrom = $_POST['original_from'] ?? '';
        $originalBody = $_POST['original_body'] ?? '';
        $userDraft = $_POST['user_draft'] ?? '';

        $systemPrompt = "You are a highly professional, friendly, and intelligent AI personal executive assistant. Your task is to write an email reply for the user based on the original email details provided.\n\n"
                      . "CRITICAL INSTRUCTIONS:\n"
                      . "1. Analyze the original email details (sender, subject, and body).\n"
                      . "2. If the user draft/instructions are EMPTY:\n"
                      . "   - Suggest a highly professional, ready-to-send reply. Maintain the language of the original email (e.g. if the original email is in German, reply in German; if English, reply in English).\n"
                      . "3. If the user draft/instructions are NOT EMPTY:\n"
                      . "   - If the user wrote notes or instructions, act strictly on them and generate the final polished reply\n"
                      . "   - If the user wrote a raw draft, refine and polish it.\n"
                      . "4. Output ONLY the clean, ready-to-send reply body text. Do NOT include any 'Subject:', 'To:', markdown code blocks (such as ```), or conversational preamble (like 'Sure, here is...'). Just output the exact reply text.";

        $userPrompt = "Original Email Sender: " . $originalFrom . "\n"
                    . "Original Email Subject: " . $originalSubject . "\n"
                    . "Original Email Body:\n" . $originalBody . "\n\n"
                    . "User's Drafted Message / Instructions:\n" . ($userDraft ?: "[Empty - please generate a professional reply suggestion]");

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        try {
            $aiReplyText = $this->agentManager->chat($messages, false);

            $this->jsonResponse([
                'status' => 'success',
                'suggested_reply' => trim($aiReplyText)
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
