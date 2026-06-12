<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Agents\ContextCondenser;

class ChatCondenseAction extends BaseAction
{
    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid session ID.'], 400);
            return;
        }

        $commit = ($_POST['commit'] ?? '0') === '1';
        $manual = ($_POST['manual'] ?? '0') === '1';

        try {
            $condenser = new ContextCondenser($this->agentManager);
            if ($commit) {
                $summary = $_POST['summary'] ?? '';
                $selectedMemories = $_POST['selected_memories'] ?? [];
                if (!is_array($selectedMemories)) {
                    $selectedMemories = [];
                }
                $result = $condenser->commitCondensation($this->db, $sessionId, $summary, $selectedMemories, $manual);
            } else {
                $result = $condenser->generateCondensationPreview($this->db, $sessionId, $manual);
            }
            $this->jsonResponse($result);
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
