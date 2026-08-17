<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Enums\Tab;

class ContextDataToggleAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $historyId = (int)($_GET['toggle_context'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;

        if ($historyId <= 0) {
            return;
        }

        $this->db->query(
            "UPDATE chat_history SET active_context = NOT COALESCE(active_context, 1) WHERE id = :id",
            [':id' => $historyId]
        );

        $rows = $this->db->query(
            "SELECT active_context FROM chat_history WHERE id = :id",
            [':id' => $historyId]
        );
        $active = !empty($rows) && (bool)$rows[0]['active_context'];

        $isAjax = (isset($_GET['ajax']) && $_GET['ajax'] == 1) || $this->isApiRequest();
        if ($isAjax) {
            $this->jsonResponse(['status' => 'success', 'active_context' => $active]);
            return;
        }

        $redirectSessionId = (int)($_GET['session_id'] ?? 0);
        if ($redirectSessionId > 0) {
            $this->redirect($this->buildUrl($redirectSessionId, $activeTab));
        }
    }
}
