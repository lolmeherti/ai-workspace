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
    private SearchMemoriesTool $searchMemoriesTool;

    public function __construct(Database $db, AgentManager $agent, string $uploadDir, ?TodoistApiClient $todoist = null)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = $uploadDir;
        $this->todoist = $todoist ?? new TodoistApiClient();

        $this->searchFilesTool = new SearchFilesTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchLocalTool = new SearchLocalTool($db, $agent, $uploadDir);
        $this->searchWebTool = new SearchWebTool($db, $uploadDir, $this->todoist);
        $this->searchSessionEvidenceTool = new SearchSessionEvidenceTool($db);
        $this->createTodoistTaskTool = new CreateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getTodoistTasksTool = new GetTodoistTasksTool($db, $agent, $uploadDir, $this->todoist);
        $this->deleteTodoistTaskTool = new DeleteTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->updateTodoistTaskTool = new UpdateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchMemoriesTool = new SearchMemoriesTool($db, $agent);
    }

    /**
     * Coerce decoded tool-call arguments into the shape the tool schemas declare.
     *
     * Models occasionally emit a scalar where the schema says list — `queries` as a JSON string, or as a
     * bare string. `implode()` on that throws a TypeError, and a TypeError inside the turn kills the SSE
     * stream before its terminal event, so the browser can only report "Connection ended before
     * completion." with no cause. Normalising once, where arguments enter the tool layer, keeps every
     * downstream consumer safe (this class at :56/:137, and the tools whose execute() is typed `array`),
     * and turns a schema violation into a logged event instead of a dead turn.
     *
     * Never branches on model identity: any model that emits a scalar gets the same treatment.
     */
    public static function normalizeToolArguments(array $args, string $toolName): array
    {
        if (!array_key_exists('queries', $args)) {
            return $args;                                    // nothing to normalise
        }

        $raw = $args['queries'];

        // Already a list: still make sure it holds usable query strings. A list with an empty string or an
        // integer in it is a schema violation too, and the tools' execute() methods are typed `array` of
        // string. Unchanged lists cost nothing and are not logged.
        if (is_array($raw)) {
            $clean = self::cleanQueryList($raw);
            if ($clean === $raw) {
                return $args;
            }
            $args['queries'] = $clean;
            self::logNormalization($toolName, $raw, 'list_sanitized', count($clean));
            return $args;
        }

        $list = [];
        $how  = 'empty_or_unusable';

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = $decoded;      $how = 'json_array_decoded';    // "[\"a\",\"b\"]"
            } elseif (is_scalar($decoded) && trim((string) $decoded) !== '') {
                $list = [$decoded];    $how = 'json_scalar_decoded';   // "\"one query\""
            } else {
                $list = [$raw];        $how = 'bare_string_wrapped';   // plain text, not JSON
            }
        } elseif (is_scalar($raw) && trim((string) $raw) !== '') {
            $list = [$raw];            $how = 'bare_scalar_wrapped';
        }

        $args['queries'] = self::cleanQueryList($list);
        self::logNormalization($toolName, $raw, $how, count($args['queries']));

        return $args;
    }

    /** Query strings only: scalars cast to string, empty and non-scalar entries dropped. */
    private static function cleanQueryList(array $list): array
    {
        return array_values(array_filter(
            array_map(static fn($q): string => is_scalar($q) ? (string) $q : '', $list),
            static fn(string $q): bool => trim($q) !== ''
        ));
    }

    /** A schema violation is observable in the event log (level warn → visible at /logs), never silent. */
    private static function logNormalization(string $toolName, mixed $raw, string $how, int $resultCount): void
    {
        \App\Logger::logEvent('tool_args_normalized', 'Tool arguments did not match the declared schema', [
            'tool_name'     => $toolName,
            'argument'      => 'queries',
            'received_type' => get_debug_type($raw),
            'received_len'  => is_string($raw) ? mb_strlen($raw) : null,
            'coerced_to'    => $how,
            'result_count'  => $resultCount,
        ], 'warn', 'ToolExecutionService::normalizeToolArguments');
    }

    public function executeToolByName(string $toolName, array $args, int $sessionId, callable $emit): string
    {
        $args = self::normalizeToolArguments($args, $toolName);   // idempotent: a list passes straight through
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

    public function getLastFailures(): array
    {
        return $this->searchWebTool->getLastFailures();
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

    /** @return array<int, array{content:string, due:string, url:string}> */
    public function getCreatedTasks(): array
    {
        return $this->createTodoistTaskTool->getCreatedTasks();
    }

    public function resetCreatedTasks(): void
    {
        $this->createTodoistTaskTool->resetCreatedTasks();
    }

    private function executeTool(string $toolName, array $toolData, int $sessionId, array $messages, callable $emit): string
    {
        $resolvedTool = Tool::tryFrom($toolName);
        if ($resolvedTool === null) {
            \App\Logger::logEvent('llm_tool_no_match', "LLM tool_call for unknown tool: {$toolName}", [
                'reason' => 'unknown_tool',
                'tool_name' => $toolName,
            ], 'warn', 'ToolExecutionService::executeTool');
            return '';
        }

        $cleanJson = json_encode($toolData);

        try {
            $result = match ($resolvedTool) {
                Tool::SEARCH_FILES => $this->searchFilesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_LOCAL => $this->searchLocalTool->execute($toolData['queries'] ?? [$toolData['query'] ?? ''], $sessionId, $messages, $emit),
                Tool::SEARCH_WEB => $this->searchWebTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_CALENDAR => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::CREATE_CALENDAR_TASK => $this->createTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_CALENDAR_TASKS => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::DELETE_CALENDAR_TASK => $this->deleteTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::UPDATE_CALENDAR_TASK => $this->updateTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
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
