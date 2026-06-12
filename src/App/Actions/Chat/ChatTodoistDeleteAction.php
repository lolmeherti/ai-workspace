<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;

class ChatTodoistDeleteAction extends BaseAction
{
    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(): void
    {
        $taskId = $_GET['task_id'] ?? '';
        if (empty($taskId)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing task ID.'], 400);
            return;
        }

        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            $toolService->makeTodoistRequest('DELETE', '/tasks/' . $taskId);

            $this->jsonResponse([
                'status' => 'success',
                'message' => 'Task successfully deleted from Todoist.'
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
