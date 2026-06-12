<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Enums\Tool;
use App\Services\Tools\TodoistApiClient;
use App\Services\Tools\SearchFilesTool;
use App\Services\Tools\CreateTodoistTaskTool;
use App\Services\Tools\GetTodoistTasksTool;
use App\Services\Tools\DeleteTodoistTaskTool;
use App\Services\Tools\UpdateTodoistTaskTool;
use App\Services\Tools\GetEmailBriefingTool;

class ToolExecutionService
{
    private Database $db;
    private AgentManager $agent;
    private string $uploadDir;
    private TodoistApiClient $todoist;
    private SearchFilesTool $searchFilesTool;
    private CreateTodoistTaskTool $createTodoistTaskTool;
    private GetTodoistTasksTool $getTodoistTasksTool;
    private DeleteTodoistTaskTool $deleteTodoistTaskTool;
    private UpdateTodoistTaskTool $updateTodoistTaskTool;
    private GetEmailBriefingTool $getEmailBriefingTool;

    public function __construct(Database $db, AgentManager $agent, string $uploadDir)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = $uploadDir;
        $this->todoist = new TodoistApiClient();

        $this->searchFilesTool = new SearchFilesTool($db, $agent, $uploadDir, $this->todoist);
        $this->createTodoistTaskTool = new CreateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getTodoistTasksTool = new GetTodoistTasksTool($db, $agent, $uploadDir, $this->todoist);
        $this->deleteTodoistTaskTool = new DeleteTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->updateTodoistTaskTool = new UpdateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getEmailBriefingTool = new GetEmailBriefingTool($db, $agent, $uploadDir, $this->todoist);
    }

    public function processToolCall(string $aiResponse, int $sessionId, array $messages, callable $emit): string
    {
        $decoded = \App\JsonParser::extractAndDecode($aiResponse);

        if (!is_array($decoded) || !isset($decoded['tool'])) {
            return $aiResponse;
        }

        $resolvedTool = Tool::tryFrom($decoded['tool']);
        if ($resolvedTool === null) {
            return $aiResponse;
        }

        $cleanJson = json_encode($decoded);

        try {
            return match ($resolvedTool) {
                Tool::SEARCH_FILES => $this->searchFilesTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
                Tool::CREATE_TODOIST_TASK => $this->createTodoistTaskTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_TODOIST_TASKS => $this->getTodoistTasksTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
                Tool::DELETE_TODOIST_TASK => $this->deleteTodoistTaskTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
                Tool::UPDATE_TODOIST_TASK => $this->updateTodoistTaskTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_EMAIL_BRIEFING => $this->getEmailBriefingTool->execute($decoded, $sessionId, $messages, $emit, $cleanJson),
            };
        } catch (\Throwable $e) {
            $emit('token', ['chunk' => "\n\n**System Error executing tool:** " . $e->getMessage()]);
            return $aiResponse;
        }
    }

    public function makeTodoistRequest(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->todoist->request($method, $endpoint, $data);
    }
}
