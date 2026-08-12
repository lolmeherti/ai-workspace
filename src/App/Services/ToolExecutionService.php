<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Agents\SemanticCacheEvaluator;
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
        $this->searchWebTool = new SearchWebTool($db, $agent, $uploadDir, $this->todoist, new SemanticCacheEvaluator($agent));
        $this->createTodoistTaskTool = new CreateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getTodoistTasksTool = new GetTodoistTasksTool($db, $agent, $uploadDir, $this->todoist);
        $this->deleteTodoistTaskTool = new DeleteTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->updateTodoistTaskTool = new UpdateTodoistTaskTool($db, $agent, $uploadDir, $this->todoist);
        $this->getEmailBriefingTool = new GetEmailBriefingTool($db, $agent, $uploadDir, $this->todoist);
        $this->searchMemoriesTool = new SearchMemoriesTool($db, $agent);
    }

    public function parseAndExecuteToolLines(string $response, int $sessionId, array $messages, callable $emit): array
    {
        $matches = $this->matchAllToolNames($response);

        if (empty($matches)) {
            \App\Logger::info("parseAndExecuteToolLines: no tool name match in response", [
                'response' => $response
            ]);
            return [];
        }

        $toolNames = array_column($matches, 'name');
        \App\Logger::logEvent('tool_name_matched', 'matchAllToolNames matched: ' . implode(', ', $toolNames), [
            'session_id' => $sessionId,
            'tool_names' => $toolNames,
            'response_preview' => mb_substr($response, 0, 200),
        ], 'info', 'ToolExecutionService::parseAndExecuteToolLines');

        $results = [];
        for ($i = 0; $i < count($matches); $i++) {
            $toolName = $matches[$i]['name'];
            $start = $matches[$i]['pos'];
            $end = ($i + 1 < count($matches)) ? $matches[$i + 1]['pos'] : strlen($response);
            $segment = substr($response, $start, $end - $start);

            $params = $this->extractParams($toolName, $segment);

            // Calendar expansion
            $toolsToCall = $toolName === 'calendar'
                ? ['get_todoist_tasks', 'create_todoist_task', 'update_todoist_task', 'delete_todoist_task']
                : [$toolName];

            foreach ($toolsToCall as $singleTool) {
                $toolData = array_merge(['tool' => $singleTool], $params);
                $queryLabel = !empty($toolData['query']) ? ": {$toolData['query']}" : '';

                $result = $this->executeTool($singleTool, $toolData, $sessionId, $messages, $emit);
                if (!empty($result)) {
                    $results[] = ['tool' => $singleTool, 'result' => $result];
                }
                $emit('trace', ['label' => "{$singleTool}{$queryLabel} completed.", 'color' => 'emerald']);
            }
        }

        return $results;
    }

    /**
     * Force-execute a tool the model missed. Used to guarantee user-selected
     * tools always run regardless of LLM output quality.
     */
    public function executeGuaranteed(string $toolName, string $query, int $sessionId, array $messages, callable $emit): string
    {
        $toolData = ['tool' => $toolName, 'query' => $query];
        return $this->executeTool($toolName, $toolData, $sessionId, $messages, $emit);
    }

    private function matchToolName(string $response): ?string
    {
        $toolNames = array_map(fn($t) => $t->value, Tool::cases());
        usort($toolNames, fn($a, $b) => strlen($b) <=> strlen($a));

        $bestPos = PHP_INT_MAX;
        $bestName = null;

        foreach ($toolNames as $name) {
            $len = strlen($name);
            $pos = 0;
            while (($pos = strpos($response, $name, $pos)) !== false) {
                if ($pos >= $bestPos) break;

                $nextPos = $pos + $len;
                $next = ($nextPos < strlen($response)) ? $response[$nextPos] : '';
                if ($next === ' ' || $next === '(' || $next === ':' || $next === '' || $next === "\n") {
                    $bestPos = $pos;
                    $bestName = $name;
                    break;
                }
                $pos++;
            }
        }

        return $bestName;
    }

    private function matchAllToolNames(string $response): array
    {
        $toolNames = array_map(fn($t) => $t->value, Tool::cases());
        usort($toolNames, fn($a, $b) => strlen($b) <=> strlen($a));

        $matches = [];
        foreach ($toolNames as $name) {
            $len = strlen($name);
            $pos = 0;
            while (($pos = strpos($response, $name, $pos)) !== false) {
                $nextPos = $pos + $len;
                $next = ($nextPos < strlen($response)) ? $response[$nextPos] : '';
                if ($next === ' ' || $next === '(' || $next === ':' || $next === '' || $next === "\n") {
                    $matches[] = ['name' => $name, 'pos' => $pos, 'len' => $len];
                    $pos = $nextPos;
                } else {
                    $pos++;
                }
            }
        }

        usort($matches, fn($a, $b) => $a['pos'] <=> $b['pos']);

        $seen = [];
        $result = [];
        foreach ($matches as $m) {
            if (!isset($seen[$m['name']])) {
                $seen[$m['name']] = true;
                $result[] = $m;
            }
        }

        return $result;
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

        // Find first occurrence of each key, sort by position in the response.
        // This fixes the reverse-order bug: if DUE_STRING appears before QUERY,
        // we process DUE_STRING first and use QUERY's position as the value boundary.
        $positions = [];
        foreach ($keys as $key) {
            $pos = strpos($rest, $key);
            if ($pos !== false) {
                $positions[] = ['key' => $key, 'pos' => $pos, 'len' => strlen($key)];
            }
        }
        usort($positions, fn($a, $b) => $a['pos'] <=> $b['pos']);

        for ($i = 0; $i < count($positions); $i++) {
            $key = $positions[$i]['key'];
            $valueStart = $positions[$i]['pos'] + $positions[$i]['len'];

            $nextKeyPos = null;
            for ($j = $i + 1; $j < count($positions); $j++) {
                if ($positions[$j]['pos'] > $positions[$i]['pos']) {
                    $nextKeyPos = $positions[$j]['pos'];
                    break;
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
            $result = match ($resolvedTool) {
                Tool::SEARCH_FILES => $this->searchFilesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_WEB => $this->searchWebTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::CREATE_TODOIST_TASK => $this->createTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_TODOIST_TASKS => $this->getTodoistTasksTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::DELETE_TODOIST_TASK => $this->deleteTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::UPDATE_TODOIST_TASK => $this->updateTodoistTaskTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::GET_EMAIL_BRIEFING => $this->getEmailBriefingTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
                Tool::SEARCH_MEMORIES => $this->searchMemoriesTool->execute($toolData, $sessionId, $messages, $emit, $cleanJson),
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
