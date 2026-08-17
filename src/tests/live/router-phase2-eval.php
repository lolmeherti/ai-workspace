<?php

declare(strict_types=1);

/*
 * Phase 2 router eval — drives the REAL ChatManager::routeRequest (Option C
 * router) via reflection against the live llama.cpp, over a broader matrix.
 *
 * Measures per turn:
 *   - router call count (1 = normal, 2 = router+fallback)
 *   - router latency + completion/prompt tokens
 *   - selection vs expected -> false tool / false no_tool
 *   - fallback count
 *   - content leakage (prose emitted alongside a tool call)
 *
 * No web search is actually executed — routeRequest only DECIDES the tool.
 * Run: docker exec ai_php_web php /var/www/html/tests/live/router-phase2-eval.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

class NoopDatabase extends \App\Database
{
    public function __construct()
    {
    }

    public function query(string $sql, array $params = []): array
    {
        return [];
    }
}

class CountingAgent extends AgentManager
{
    public array $calls = [];

    public function chatWithTools(array $messages, array $tools, string $toolChoice = 'auto', ?float $temperature = null, int $maxTokens = 4096): array
    {
        $start = microtime(true);
        $r = parent::chatWithTools($messages, $tools, $toolChoice, $temperature, $maxTokens);
        $names = array_map(fn($t) => $t['function']['name'] ?? '?', $r['tool_calls'] ?? []);
        $this->calls[] = [
            'tool_choice' => $toolChoice,
            'finish_reason' => $r['finish_reason'] ?? '?',
            'tool_names' => $names,
            'content_chars' => mb_strlen($r['content'] ?? ''),
            'completion_tokens' => $r['usage']['completion_tokens'] ?? null,
            'prompt_tokens' => $r['usage']['prompt_tokens'] ?? null,
            'ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        return $r;
    }
}

$db = new NoopDatabase();
$agent = new CountingAgent();
$chatManager = new ChatManager($db, $agent);
$prompt = new PromptAssemblyService($db, sys_get_temp_dir(), fn(string $t): int => strlen($t));

$ref = new ReflectionMethod(ChatManager::class, 'routeRequest');
$ref->setAccessible(true);

$cases = [
    ['greeting', 'Thank you!', 'no_tool'],
    ['math', 'What is 2+2?', 'no_tool'],
    ['knowledge', 'Can you explain what the word serendipity means?', 'no_tool'],
    ['opinion', 'What should I name my new cat?', 'no_tool'],
    ['translation', 'Translate "good morning" to German.', 'no_tool'],
    ['howto', 'How do I list files by size in bash?', 'no_tool'],
    ['poem', 'Write a short haiku about winter.', 'no_tool'],
    ['NBA 2026', 'Who won the 2026 NBA Finals?', 'search_web'],
    ['weather now', 'What is the weather like in Vienna right now?', 'search_web'],
    ['stock news', 'What are the latest Tesla stock news?', 'search_web'],
    ['gold price', 'How much does gold cost today?', 'search_web'],
    ['president now', 'Who is the current President of the United States?', 'search_web'],
    ['recent event', 'What happened in the news today?', 'search_web'],
    ['files', 'Search my files for my CV.', 'search_local'],
    ['memories', 'What do I have saved about my trip to Japan?', 'search_local'],
    ['calendar events', "What's on my calendar this week?", 'search_calendar'],
    ['tasks', 'Do I have any tasks due tomorrow?', 'search_calendar'],
];

$falseTool = 0;
$falseNoTool = 0;
$fallbacks = 0;
$leaks = 0;
$correct = 0;
$rows = [];

foreach ($cases as [$label, $query, $expect]) {
    $agent->calls = [];
    $history = [['role' => 'user', 'message' => $query, 'message_type' => 'text']];
    $assistantMessages = $prompt->buildMessagesArray($prompt->buildSystemPrompt($query, false), $history);

    $routing = $ref->invoke($chatManager, $history, $assistantMessages);

    $toolNames = array_column(array_column($routing['tool_calls'] ?? [], 'function'), 'name');
    $fallback = (bool) ($routing['fallback'] ?? false);
    $routerCall = $agent->calls[0] ?? [];
    $leak = (int) ($routerCall['content_chars'] ?? 0) > 0;

    if (!empty($toolNames)) {
        $got = implode(',', $toolNames);
    } elseif ($fallback) {
        $got = 'fallback';
    } else {
        $got = 'no_tool';
    }

    $ok = false;
    if ($expect === 'no_tool') {
        $ok = ($got === 'no_tool');
        if (!$ok && $got !== 'fallback') {
            $falseTool++;
        }
    } else {
        $ok = ($got === $expect);
        if (!$ok && $got === 'no_tool') {
            $falseNoTool++;
        }
    }
    if ($ok) {
        $correct++;
    }
    if ($fallback) {
        $fallbacks++;
    }
    if ($leak) {
        $leaks++;
    }

    $rows[] = sprintf(
        "  [%s] %-16s want=%-15s got=%-15s calls=%d latency=%dms ct=%s prompt=%s leak=%s",
        $ok ? 'PASS' : 'FAIL',
        $label,
        $expect,
        $got,
        count($agent->calls),
        $routerCall['ms'] ?? 0,
        var_export($routerCall['completion_tokens'] ?? null, true),
        var_export($routerCall['prompt_tokens'] ?? null, true),
        $leak ? 'YES' : 'no'
    );
}

echo "PHASE 2 ROUTER EVAL (live, real ChatManager::routeRequest)\n";
echo "model: " . Config::get('LLM_MODEL_NAME', '?') . "\n\n";
foreach ($rows as $row) {
    echo $row . "\n";
}

$total = count($cases);
printf("\nACCURACY: %d/%d\n", $correct, $total);
printf("false tool selections: %d\n", $falseTool);
printf("false no_tool selections: %d\n", $falseNoTool);
printf("fallbacks: %d\n", $fallbacks);
printf("content leaks: %d\n", $leaks);
