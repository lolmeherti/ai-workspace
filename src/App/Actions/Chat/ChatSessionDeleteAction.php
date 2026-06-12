<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Repositories\ChatSessionRepository;

class ChatSessionDeleteAction extends BaseAction
{
    public function __construct(
        private $db,
        private ChatSessionRepository $chatSessionRepository
    ) {
    }

    public function execute(): void
    {
        if (!$this->db) {
            $this->redirect("index.php?new_chat=1");
            return;
        }

        $selectedIds = $_POST['selected_sessions'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->chatSessionRepository->deleteMultiple($selectedIds);
        }
        $this->redirect("index.php?new_chat=1");
    }
}
