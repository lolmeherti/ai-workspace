<?php

declare(strict_types=1);

/*
 * Todoist create probe — live.
 *
 * Verifies the newly-exposed create_calendar_task native tool end to end
 * against the ACTUAL running llama.cpp + prompt template + Todoist API:
 *
 *   Section A (no side effects) — drives the real ChatManager::firstPass via
 *     reflection, using the real buildSystemPrompt() + buildToolSchemas(). For
 *     each prompt it reports the tool the model selected and the raw
 *     arguments. Confirms (a) the model sees create_calendar_task, (b) it
 *     chooses it for "add to todoist" prompts, (c) it passes content and
 *     due_string, and (d) read-only routing (search_calendar) is unbroken.
 *
 *   Section B (real Todoist writes) — drives the real ToolExecutionService.
 *     Creates a clearly-labelled test task, verifies it via GET /tasks,
 *     re-creates it to exercise the conflict path (must NOT create a
 *     duplicate; must return the [CalendarSuggest: ...] card), then deletes the
 *     test task. Leaves Todoist clean.
 *
 * Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/todoist-create-probe.php
 *
 * Add --keep to skip the Section B cleanup and leave the test task in Todoist.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

Config::load(dirname(__DIR__, 2));

$keep = in_array('--keep', $argv ?? [], true);

final class CountingAgent extends AgentManager
{
    public int $calls = 0;

    public function chatToolCapable(array $messages, array $tools, string $toolChoice, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): array
    {
        $this->calls++;
        return parent::chatToolCapable($messages, $tools, $toolChoice, $streamCallback, $temperature, $purpose);
    }
}

$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
}

function info(string $label, ?string $value): void
{
    printf("  [INFO] %s%s\n", $label, $value !== null && $value !== '' ? ": \"{$value}\"" : ': (empty)');
}

$db = new Database();
$db->initTables();

$agent = new CountingAgent();
$cm = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, Config::getProjectRoot() . '/uploads/');

$rc = new ReflectionClass(ChatManager::class);
$firstPass = $rc->getMethod('firstPass');
$firstPass->setAccessible(true);
$schemas = $rc->getMethod('buildToolSchemas');
$schemas->setAccessible(true);

$toolNames = [];
$createDesc = null;
foreach ($schemas->invoke($cm) as $t) {
    $toolNames[] = $t['function']['name'] ?? '?';
    if (($t['function']['name'] ?? '') === 'create_calendar_task') {
        $createDesc = $t['function']['description'] ?? null;
    }
}

echo "=== TODOIST CREATE PROBE (live) ===\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
echo 'exposed tools: ' . implode(', ', $toolNames) . "\n";
echo 'create_calendar_task description: ' . var_export($createDesc, true) . "\n\n";

echo "--- Section A: decision probe (real firstPass, no writes) ---\n\n";

$cases = [
    ['create-dentist',   'Add a task to my Todoist to call the dentist tomorrow at 10am', 'create_calendar_task'],
    ['create-diabetes',  'Note this in my Todoist: diabetes discussion on September 29 at 10:00', 'create_calendar_task'],
    ['create-milk',      'Create a todoist task: buy milk', 'create_calendar_task'],
    ['create-remind',    'Remind me to call my mom', 'create_calendar_task'],
    ['create-add',       "Add 'renew passport' to my todo list", 'create_calendar_task'],
    ['create-note',      'Note: pick up dry cleaning', 'create_calendar_task'],
    ['create-schedule',  'Schedule lunch with Sarah tomorrow at noon', 'create_calendar_task'],
    ['create-bloodtest', 'Add to Todoist: blood test on September 22 between 8:30 and 10:00', 'create_calendar_task'],
    ['create-multi',     'Add these to my Todoist: diabetes discussion Sept 29 10:00, and blood test Sept 22 8:30', 'create_calendar_task'],
    ['ctrl-calendar',    "What's on my Todoist this week?", 'search_calendar'],
    ['ctrl-files',       'Do I have a file about my car insurance?', 'search_local'],
    ['ctrl-hello',       'Hello!', null],
];

foreach ($cases as [$label, $query, $expect]) {
    $agent->calls = 0;
    $tokenChunks = 0;
    $emit = function (string $event, array $data = []) use (&$tokenChunks) {
        if ($event === 'token') {
            $tokenChunks++;
        }
    };

    $messages = [
        ['role' => 'system', 'content' => $pas->buildSystemPrompt($query) . $pas->dateContextLine()],
        ['role' => 'user', 'content' => $query],
    ];

    $t0 = microtime(true);
    try {
        $result = $firstPass->invoke($cm, $messages, $emit);
    } catch (\Throwable $e) {
        echo "CASE {$label}: {$query}\n";
        echo "  ERROR: " . $e->getMessage() . " (is llama.cpp up?)\n\n";
        $fail++;
        continue;
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if (!is_array($result)) {
        echo "CASE {$label}: {$query}\n";
        echo "  ERROR: firstPass returned no result\n\n";
        $fail++;
        continue;
    }

    $finish = $result['finish_reason'] ?? '?';
    $pt = $result['usage']['prompt_tokens'] ?? null;
    $ct = $result['usage']['completion_tokens'] ?? null;

    echo "CASE {$label}: {$query}\n";
    printf("  finish=%s calls=%d ms=%d prompt_tokens=%s completion_tokens=%s content_chunks=%d\n",
        $finish, $agent->calls, $ms, var_export($pt, true), var_export($ct, true), $tokenChunks);

    $names = [];
    $creates = [];
    $selected = [];
    foreach (($result['tool_calls'] ?? []) as $tc) {
        $fn = $tc['function'] ?? [];
        $name = $fn['name'] ?? '';
        $names[] = $name;
        $args = json_decode($fn['arguments'] ?? '{}', true) ?: [];
        echo '  tool_call name=' . $name . ' args=' . json_encode($args) . "\n";
        $selected[] = $name;
        if ($name === 'create_calendar_task') {
            $creates[] = $args;
        }
    }
    if (empty($names)) {
        echo "  tool_call name=(none)\n";
    }

    if ($expect === 'create_calendar_task') {
        $ok = !empty($creates);
        check('selected create_calendar_task', $ok);
        if ($ok) {
            foreach ($creates as $a) {
                $content = (string) ($a['content'] ?? '');
                $due = $a['due_string'] ?? null;
                check('content non-empty', $content !== '');
                info('due_string', $due);
            }
            info('create call count', (string) count($creates));
        }
    } elseif ($expect === null) {
        check('no tool selected', empty($selected));
    } else {
        check("selected {$expect}", in_array($expect, $selected, true));
    }

    echo "\n";
}

echo "\n--- Section B: execution probe (REAL Todoist; create + verify + duplicate + cleanup) ---\n\n";

if (empty(Config::get('TODOIST_API_KEY'))) {
    echo "SKIPPED: TODOIST_API_KEY not configured.\n";
} else {
    $tes = new ToolExecutionService($db, $agent, Config::getProjectRoot() . '/uploads/');
    $emitted = [];
    $capture = function (string $event, array $data = []) use (&$emitted) {
        $emitted[] = ['event' => $event, 'data' => $data];
    };

    $content = 'Localsy e2e test ' . date('His');
    $due = 'tomorrow 9:00';

    echo "B1 clear create: content=\"{$content}\" due=\"{$due}\"\n";
    try {
        $r1 = $tes->executeToolByName('create_calendar_task', ['content' => $content, 'due_string' => $due], 0, $capture);
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        $fail++;
        goto summary;
    }
    echo '  result: ' . str_replace("\n", "\n  ", $r1) . "\n";
    $reportsSuccess = stripos($r1, 'successfully created') !== false;
    check('tool reported successful create', $reportsSuccess);

    $resp = $tes->makeTodoistRequest('GET', '/tasks');
    $tasks = $resp['results'] ?? (is_array($resp) ? $resp : []);
    $created = null;
    foreach ($tasks as $t) {
        if (($t['content'] ?? '') === $content) {
            $created = $t;
            break;
        }
    }
    check('GET /tasks found the created task', $created !== null);
    if ($created !== null) {
        info('created task id', (string) $created['id']);
    }

    $createdTasks = $tes->getCreatedTasks();
    if (!empty($createdTasks)) {
        $d = $createdTasks[0];
        echo '  recorded task: ' . json_encode($d) . "\n";
        check('recorded content matches', ($d['content'] ?? '') === $content);
        check('recorded has due', !empty($d['due'] ?? null));
        check('recorded has url', !empty($d['url'] ?? null));
    } else {
        check('task recorded by tool', false);
    }
    $createdCount = count($createdTasks);

    echo "\nB2 duplicate create (same content+due):\n";
    $r2 = $tes->executeToolByName('create_calendar_task', ['content' => $content, 'due_string' => $due], 0, $capture);
    echo '  result: ' . str_replace("\n", "\n  ", $r2) . "\n";
    check('conflict detected (no second create)', stripos($r2, 'conflict') !== false);
    check('[CalendarSuggest: card present', strpos($r2, '[CalendarSuggest:') !== false);

    $resp2 = $tes->makeTodoistRequest('GET', '/tasks');
    $tasks2 = $resp2['results'] ?? (is_array($resp2) ? $resp2 : []);
    $count = 0;
    foreach ($tasks2 as $t) {
        if (($t['content'] ?? '') === $content) {
            $count++;
        }
    }
    check('exactly one task with that content (no duplicate)', $count === 1);

    $postB2Created = count($tes->getCreatedTasks());
    check('no task recorded on conflict', $postB2Created === $createdCount);

    if ($created !== null && !$keep) {
        $del = $tes->makeTodoistRequest('DELETE', '/tasks/' . $created['id']);
        echo "\nB3 cleanup: DELETE /tasks/{$created['id']} -> " . json_encode($del) . "\n";
        $resp3 = $tes->makeTodoistRequest('GET', '/tasks');
        $tasks3 = $resp3['results'] ?? (is_array($resp3) ? $resp3 : []);
        $stillThere = false;
        foreach ($tasks3 as $t) {
            if (($t['id'] ?? '') === $created['id']) {
                $stillThere = true;
                break;
            }
        }
        check('test task removed', !$stillThere);
    } elseif ($created !== null) {
        echo "\nB3 cleanup skipped (--keep). Test task id={$created['id']} left in Todoist.\n";
    }
}

summary:
echo "\n========================================\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
