<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Enums\Tool;
use App\Services\Tools\TodoistApiClient;
use App\Services\Tools\SearchFilesTool;
use App\Services\Tools\SearchWebTool;
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
    private SearchWebTool $searchWebTool;
    private CreateTodoistTaskTool $createTodoistTaskTool;
    private GetTodoistTasksTool $getTodoistTasksTool;
    private DeleteTodoistTaskTool $deleteTodoistTaskTool;
    private UpdateTodoistTaskTool $updateTodoistTaskTool;
    private GetEmailBriefingTool $getEmailBriefingTool;
    private SearchMemoriesTool $searchMemoriesTool;

    private const TOOL_KEYS = [
        'search_files'        => ['QUERY:'],
        'search_web'          => ['QUERY:'],
        'search_memories'     => ['QUERY:'],
        'get_todoist_tasks'   => ['QUERY:'],
        'delete_todoist_task' => ['QUERY:'],
        'create_todoist_task' => ['QUERY:', 'DUE_STRING:'],
        'update_todoist_task' => ['QUERY:', 'NEW_CONTENT:', 'NEW_DUE_STRING:'],
    ];

    public function __construct(Database $db, AgentManager $agent, string $uploadDir)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->uploadDir = $uploadDir;
        $this->todoist = new TodoistApiClient();

        $this->searchFilesTool = new SearchFilesTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchWebTool = new SearchWebTool($db, $agent, $uploadDir, $this->todoist);
        $this->createTodoistTaskTool = new CreateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getTodoistTasksTool = new GetTodoistTasksTool($db, $agent, $uploadDir, $this->todoist);
        $this->deleteTodoistTaskTool = new DeleteTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->updateTodoistTaskTool = new UpdateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getEmailBriefingTool = new GetEmailBriefingTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchMemoriesTool = new SearchMemoriesTool($db, $agent);
    }

    public function parseAndExecuteToolLines(string $response, int $sessionId, array $messages, callable $emit): array
    {
        $results = [];
        $toolName = $this->matchToolName($response);

        if ($toolName === null) {
            \App\Logger::info("parseAndExecuteToolLines: no tool name match in response", [
                'response' => $response
            ]);
            return $results;
        }

        $params = $this->extractParams($toolName, $response);

        // Calendar expansion
        $toolsToCall = $toolName === 'calendar'
            ? ['get_todoist_tasks', 'create_todoist_task', 'update_todoist_task', 'delete_todoist_task']
            : [$toolName];

        foreach ($toolsToCall as $singleTool) {
            $toolData = array_merge(['tool' => $singleTool], $params);

            $emit('tool_start', ['tool' => $singleTool, 'label' => "Executing {$singleTool}..."]);

            $result = $this->executeTool($singleTool, $toolData, $sessionId, $messages, $emit);
            if (!empty($result)) {
                $results[] = ['tool' => $singleTool, 'result' => $result];
            }
        }

        return $results;
    }

    private function matchToolName(string $response): ?string
    {
        $toolNames = array_map(fn($t) => $t->value, Tool::cases());
        usort($toolNames, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($toolNames as $name) {
            $len = strlen($name);
            if (strlen($response) < $len) continue;
            if (substr($response, 0, $len) !== $name) continue;

            $next = substr($response, $len, 1);
            if ($next === ' ' || $next === '(' || $next === ':' || $next === '' || $next === "\n") {
                return $name;
            }
        }

        return null;
    }

    private function extractParams(string $toolName, string $response): array
    {
        $keys = self::TOOL_KEYS[$toolName] ?? ['QUERY:'];
        $params = [];

        $rest = substr($response, strlen($toolName));
        $rest = ltrim($rest);

        // Handle function-call format: tool_name(KEY="VALUE", KEY="VALUE")
        if (str_starts_with($rest, '(')) {
            return $this->extractFunctionCallParams($keys, $rest);
        }

        // Original colon-based format: tool_name KEY:VALUE KEY:VALUE
        return $this->extractColonParams($keys, $rest);
    }

    private function extractFunctionCallParams(array $keys, string $rest): array
    {
        $params = [];

        foreach ($keys as $key) {
            $keyName = rtrim($key, ':');
            // Match: KEY="VALUE" or KEY='VALUE' or KEY=VALUE (unquoted to next comma/paren)
            $patterns = [
                '/\b' . preg_quote($keyName, '/') . '\s*=\s*"([^"]*)"/',
                '/\b' . preg_quote($keyName, '/') . "\s*=\s*'([^']*)'/",
                '/\b' . preg_quote($keyName, '/') . '\s*=\s*([^,)]+)/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $rest, $m)) {
                    $params[strtolower($keyName)] = trim($m[1]);
                    break;
                }
            }
        }

        if (!isset($params['query'])) {
            $params['query'] = '';
        }

        return $params;
    }

    private function extractColonParams(array $keys, string $rest): array
    {
        $params = [];

        for ($i = 0; $i < count($keys); $i++) {
            $key = $keys[$i];
            $pos = strpos($rest, $key);
            if ($pos === false) continue;

            $valueStart = $pos + strlen($key);
            $nextKeyPos = null;
            for ($j = $i + 1; $j < count($keys); $j++) {
                $nkPos = strpos($rest, $keys[$j], $valueStart);
                if ($nkPos !== false && ($nextKeyPos === null || $nkPos < $nextKeyPos)) {
                    $nextKeyPos = $nkPos;
                }
            }

            $value = $nextKeyPos !== null
                ? substr($rest, $valueStart, $nextKeyPos - $valueStart)
                : substr($rest, $valueStart);

            $paramName = strtolower(rtrim($key, ':'));
            $params[$paramName] = trim($value);
        }

        if (!isset($params['query'])) {
            $params['query'] = '';
        }

        return $params;
    }

    private function executeTool(string $toolName, array $toolData, int $sessionId, array $messages, callable $emit): string
    {
        $resolvedTool = Tool::tryFrom($toolName);
        if ($resolvedTool === null) {
            return '';
        }

        $cleanJson = json_encode($toolData);

        try {
            return match ($resolvedTool) {
                Tool::SEARCH_FILES => $this->searchFilesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_WEB => $this->searchWebTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::CREATE_TODOIST_TASK => $this->createTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_TODOIST_TASKS => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::DELETE_TODOIST_TASK => $this->deleteTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::UPDATE_TODOIST_TASK => $this->updateTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_EMAIL_BRIEFING => $this->getEmailBriefingTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_MEMORIES => $this->searchMemoriesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
            };
        } catch (\Throwable $e) {
            return "System tool execution error: " . $e->getMessage();
        }
    }

    public function makeTodoistRequest(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->todoist->request($method, $endpoint, $data);
    }
}
