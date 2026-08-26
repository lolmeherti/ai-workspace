<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Enums\Tool;
use App\Services\Tools\TodoistApiClient;
use App\Services\Tools\SearchFilesTool;
use App\Services\Tools\SearchLocalTool;
use App\Services\Tools\SearchWebTool;
use App\Services\Tools\SearchSessionEvidenceTool;
use App\Services\Tools\CreateTodoistTaskTool;
use App\Services\Tools\GetTodoistTasksTool;
use App\Services\Tools\DeleteTodoistTaskTool;
use App\Services\Tools\UpdateTodoistTaskTool;
use App\Services\Tools\GetEmailBriefingTool;
use App\Services\Tools\SearchMemoriesTool;

class ToolExecutionService
{
    private Database $db;
    private AgentManager $agent;
    private string $uploadDir;
    private TodoistApiClient $todoist;
    private SearchFilesTool $searchFilesTool;
    private SearchLocalTool $searchLocalTool;
    private SearchWebTool $searchWebTool;
    private SearchSessionEvidenceTool $searchSessionEvidenceTool;
    private CreateTodoistTaskTool $createTodoistTaskTool;
    private GetTodoistTasksTool $getTodoistTasksTool;
    private DeleteTodoistTaskTool $deleteTodoistTaskTool;
    private UpdateTodoistTaskTool $updateTodoistTaskTool;
    private GetEmailBriefingTool $getEmailBriefingTool;
    private SearchMemoriesTool $searchMemoriesTool;

    public function __construct(Database $db, AgentManager $agent, string $uploadDir)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = $uploadDir;
        $this->todoist = new TodoistApiClient();

        $this->searchFilesTool = new SearchFilesTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchLocalTool = new SearchLocalTool($db, $agent, $uploadDir);
        $this->searchWebTool = new SearchWebTool($db, $uploadDir, $this->todoist);
        $this->searchSessionEvidenceTool = new SearchSessionEvidenceTool($db);
        $this->createTodoistTaskTool = new CreateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getTodoistTasksTool = new GetTodoistTasksTool($db, $agent, $uploadDir, $this->todoist);
        $this->deleteTodoistTaskTool = new DeleteTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->updateTodoistTaskTool = new UpdateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getEmailBriefingTool = new GetEmailBriefingTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchMemoriesTool = new SearchMemoriesTool($db, $agent);
    }

    public function executeToolByName(string $toolName, array $args, int $sessionId, callable $emit): string
    {
        $toolData = array_merge(['tool' => $toolName], $args);
        if (isset($toolData['queries']) && !isset($toolData['query'])) {
            $toolData['query'] = implode(', ', $toolData['queries']);
        }
        $label = $toolData['content'] ?? $toolData['query'] ?? '';

        \App\ProgressWriter::write($sessionId, 'tool_start', "Executing {$toolName}: " . $label, 'slate');

        $result = $this->executeTool($toolName, $toolData, $sessionId, [], $emit);

        \App\ProgressWriter::write($sessionId, 'tool_done', "{$toolName} completed.", 'emerald');

        return $result;
    }

    public function getLastSourceMap(): array
    {
        return $this->searchWebTool->getLastSourceMap();
    }

    public function getLastBackingChunks(): array
    {
        return $this->searchWebTool->getLastBackingChunks();
    }

    public function getLastSelectedChunks(): array
    {
        return $this->searchWebTool->getLastSelectedChunks();
    }

    public function resetSourceMap(): void
    {
        $this->searchWebTool->resetSourceMap();
    }

    public function resetBackingChunks(): void
    {
        $this->searchWebTool->resetBackingChunks();
    }

    public function resetSelectedChunks(): void
    {
        $this->searchWebTool->resetSelectedChunks();
    }

    public function getLastRetrievedSourceIds(): array
    {
        return $this->searchSessionEvidenceTool->getLastRetrievedSourceIds();
    }

    public function resetRetrievedSourceIds(): void
    {
        $this->searchSessionEvidenceTool->resetRetrievedSourceIds();
    }

    private function executeTool(string $toolName, array $toolData, int $sessionId, array $messages, callable $emit): string
    {
        $resolvedTool = Tool::tryFrom($toolName);
        if ($resolvedTool === null) {
            return '';
        }

        $cleanJson = json_encode($toolData);

        try {
            $result = match ($resolvedTool) {
                Tool::SEARCH_FILES => $this->searchFilesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_LOCAL => $this->searchLocalTool->execute($toolData['queries'] ?? [$toolData['query'] ?? ''], $sessionId, $messages, $emit),
                Tool::SEARCH_WEB => $this->searchWebTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_CALENDAR => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::CREATE_TODOIST_TASK => $this->createTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_TODOIST_TASKS => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::DELETE_TODOIST_TASK => $this->deleteTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::UPDATE_TODOIST_TASK => $this->updateTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_EMAIL_BRIEFING => $this->getEmailBriefingTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_MEMORIES => $this->searchMemoriesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_SESSION_EVIDENCE => $this->searchSessionEvidenceTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
            };

            \App\Logger::logEvent('tool_executed', "Tool {$toolName} executed successfully", [
                'tool_name' => $toolName,
                'result_len' => strlen($result),
                'result_preview' => mb_substr($result, 0, 200),
                'result_last200' => mb_substr($result, -200),
            ], 'info', 'ToolExecutionService::executeTool');

            return $result;
        } catch (\Throwable $e) {
            \App\Logger::logEvent('tool_execution_failed', "Tool {$toolName} failed: " . $e->getMessage(), [
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'error', 'ToolExecutionService::executeTool');

            return "System tool execution error: " . $e->getMessage();
        }
    }

    public function makeTodoistRequest(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->todoist->request($method, $endpoint, $data);
    }
}
