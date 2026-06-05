<?php

namespace App\Controllers;

use App\Enums\Action;
use App\Enums\Tab;
use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;

class MemoryController extends BaseController
{
    private $db;
    private $memoryRepository;
    private $chatSessionRepository;
    private $memoryExtractor;

    public function __construct($db, MemoryRepository $memoryRepository, ChatSessionRepository $chatSessionRepository, $memoryExtractor)
    {
        $this->db = $db;
        $this->memoryRepository = $memoryRepository;
        $this->chatSessionRepository = $chatSessionRepository;
        $this->memoryExtractor = $memoryExtractor;
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
        $action = $this->resolvePostAction();

        if ($action === null) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        switch ($action) {
            case Action::MANUAL_CONSOLIDATE:
                $this->manualConsolidate($sessionId);
                break;
            case Action::ADD_MEMORY:
                $this->addMemory($sessionId);
                break;
            case Action::DELETE_MEMORY:
                $this->deleteMemory($sessionId);
                break;
            case Action::DELETE_MULTIPLE_MEMORIES:
                $this->deleteMultipleMemories($sessionId);
                break;
            case Action::UPDATE_MEMORY:
                $this->updateMemory($sessionId);
                break;
        }
    }

    private function manualConsolidate(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $chatText = "";
        if ($sessionId > 0) {
            $history = $this->chatSessionRepository->getHistory($sessionId);
            foreach ($history as $row) {
                $role = ucfirst($row['role']);
                $message = $row['message'];
                $chatText .= "{$role}: {$message}\n\n";
            }
        }

        $this->memoryExtractor->extractAndSave($chatText, true);
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function addMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $count = $this->memoryRepository->getCount();
        if ($count < 500) {
            $text = trim($_POST['memory_text'] ?? '');
            if (!empty($text)) {
                $this->memoryRepository->add($text);
            }
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $memoryId = (int)($_POST['memory_id'] ?? 0);
        $this->memoryRepository->delete($memoryId);
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteMultipleMemories(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $selectedIds = $_POST['selected_memories'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->memoryRepository->deleteMultiple($selectedIds);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function updateMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $memoryId = (int)($_POST['memory_id'] ?? 0);
        $text = trim($_POST['memory_text'] ?? '');
        if (!empty($text)) {
            $this->memoryRepository->update($memoryId, $text);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function resolvePostAction(): ?Action
    {
        $actionValue = $_POST['action'] ?? null;
        if ($actionValue !== null) {
            return Action::tryFrom($actionValue);
        }

        foreach (Action::cases() as $action) {
            if (isset($_POST[$action->value])) {
                return $action;
            }
        }

        return null;
    }
}