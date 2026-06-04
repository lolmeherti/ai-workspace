<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\MemoryExtractor;
use App\Config;

class ChatManager
{
    private Database $db;
    private AgentManager $agent;
    private ?MemoryExtractor $memoryExtractor;
    private string $uploadDir;

    public function __construct(Database $db, AgentManager $agent, ?MemoryExtractor $memoryExtractor = null)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->memoryExtractor = $memoryExtractor;
        $this->uploadDir = __DIR__ . '/../uploads/';
    }

    public function process(int $sessionId, string $query, ?array $imageFile): array
    {
        $imagePath = null;
        $updatedTitle = null;
        
        if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
            if (!is_dir($this->uploadDir)) {
                mkdir($this->uploadDir, 0755, true);
            }
            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($imageFile['name']));
            $dest = $this->uploadDir . $filename;
            if (move_uploaded_file($imageFile['tmp_name'], $dest)) {
                $imagePath = 'uploads/' . $filename;
            }
        }

        if ($this->memoryExtractor) {
            $this->memoryExtractor->runIfThresholdMet($sessionId);
        }

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'user',
            'message' => $query,
            'image_path' => $imagePath,
            'token_estimate' => (int)(mb_strlen($query) / 4)
        ]);

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);

        if (count($history) === 1) {
            $titlePrompt = [
                ['role' => 'system', 'content' => 'You are a title generator. Generate an extremely short 2-3 word title representing the user query. Do not use quotes, markdown, punctuation, or explanations. Keep it under 20 characters.'],
                ['role' => 'user', 'content' => $query]
            ];
            $temperature = (float) Config::get('AGENT_TITLE_TEMP', 0.4);
            $newTitle = trim($this->agent->chat($titlePrompt, false, null, $temperature));
            if (!empty($newTitle) && strlen($newTitle) < 50) {
                $this->db->update('chat_sessions', ['title' => $newTitle], ['id' => $sessionId]);
                $updatedTitle = $newTitle;
            }
        }

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => 'You are a helpful, friendly, and highly intelligent AI conversational assistant.'
        ];

        foreach ($history as $row) {
            if (!empty($row['image_path']) && file_exists(__DIR__ . '/../' . $row['image_path'])) {
                $mimeType = mime_content_type(__DIR__ . '/../' . $row['image_path']);
                $base64   = base64_encode(file_get_contents(__DIR__ . '/../' . $row['image_path']));
                $messages[] = [
                    'role' => $row['role'],
                    'content' => [
                        ['type' => 'text', 'text' => $row['message']],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                    ]
                ];
            } else {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $row['message']
                ];
            }
        }

        $aiResponse = $this->agent->chat($messages, false);

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'assistant',
            'message' => $aiResponse,
            'image_path' => null,
            'token_estimate' => (int)(mb_strlen($aiResponse) / 4)
        ]);

        return [
            'status' => 'success',
            'message' => $aiResponse,
            'title' => $updatedTitle
        ];
    }
}