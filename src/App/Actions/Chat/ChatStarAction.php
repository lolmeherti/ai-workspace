<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Enums\Tab;
use App\Repositories\ChatSessionRepository;

class ChatStarAction extends BaseAction
{
    public function __construct(private ChatSessionRepository $chatSessionRepository)
    {
    }

    public function execute(): void
    {
        $starSessionId = (int)($_GET['toggle_star'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;

        if ($starSessionId > 0) {
            $isStarred = $this->chatSessionRepository->toggleStar($starSessionId);

            $isAjax = (isset($_GET['ajax']) && $_GET['ajax'] == 1) || $this->isApiRequest();
            if ($isAjax) {
                $this->jsonResponse(['status' => 'success', 'is_starred' => $isStarred]);
                return;
            }

            $redirectSessionId = (int)($_GET['session_id'] ?? $starSessionId);
            $this->redirect($this->buildUrl($redirectSessionId, $activeTab));
        }
    }
}
