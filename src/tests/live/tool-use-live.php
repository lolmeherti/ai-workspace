<?php

declare(strict_types=1);

/*
 * Tool-use routing eval — LIVE, independent of run.php.
 *
 * The model's tool SELECTION is a sampling behavior and can never be unit
 * tested, so it lives here, in its own script, driven against the real
 * llama.cpp + prompt template + tool schemas. It routes a full matrix of
 * prompts through the real ChatManager::firstPass and measures how often the
 * model picks the right tool.
 *
 * Covers every tool exposed to the model except search_session_evidence
 * (which requires prior web evidence already in the conversation and can't be
 * triggered from a cold single turn).
 *
 * Usage:
 *   docker exec ai_php_web php /var/www/html/tests/live/tool-use-live.php
 *   docker exec ai_php_web php /var/www/html/tests/live/tool-use-live.php --passes 3 --threshold 0.9
 *   docker exec ai_php_web php /var/www/html/tests/live/tool-use-live.php --with-execution
 *
 * --passes N          run the routing matrix N times (default 1)
 * --threshold X       pass/fail accuracy threshold (default 0.9)
 * --with-execution    also run the REAL Todoist create/conflict/delete path
 *                     (writes + cleans up a clearly-labelled test task)
 *
 * Exit code 0 when aggregate accuracy >= threshold, else 1.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

Config::load(dirname(__DIR__, 2));

$argv = $argv ?? [];
$passes = 1;
$threshold = 0.9;
$withExec = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--passes' && isset($argv[$i + 1])) {
        $passes = max(1, (int) $argv[++$i]);
    } elseif ($argv[$i] === '--threshold' && isset($argv[$i + 1])) {
        $threshold = (float) $argv[++$i];
    } elseif ($argv[$i] === '--with-execution') {
        $withExec = true;
    }
}

$db = new Database();
$db->initTables();

$agent = new AgentManager();
$cm = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, Config::getProjectRoot() . '/uploads/');

$rc = new ReflectionClass(ChatManager::class);
$firstPass = $rc->getMethod('firstPass');
$firstPass->setAccessible(true);

// [label, prompt, expected tool name (or null for "answer directly")]
$cases = [
    ['files',       'Do I have a file about my car insurance?', 'search_local'],
    ['memory',      'What do I remember about my trip to Japan?', 'search_local'],
    ['notes',       'Find my notes about the kitchen renovation', 'search_local'],
    ['weather',     "What's the weather in Vienna right now?", 'search_web'],
    ['nba',         'Who won the 2026 NBA Finals?', 'search_web'],
    ['news',        'What are the top news headlines today?', 'search_web'],
    ['calendar',    "What's on my calendar this week?", 'search_calendar'],
    ['todolist',    "What's on my todo list for today?", 'search_calendar'],
    ['dentist',     'Add a task to my Todoist to call the dentist tomorrow at 10am', 'create_calendar_task'],
    ['note',        'Note: pick up dry cleaning', 'create_calendar_task'],
    ['schedule',    'Schedule lunch with Sarah tomorrow at noon', 'create_calendar_task'],
    ['bloodtest',   'Add to Todoist: blood test on September 22 between 8:30 and 10:00', 'create_calendar_task'],
    ['multi',       'Add these to my Todoist: diabetes discussion Sept 29 10:00, and blood test Sept 22 8:30', 'create_calendar_task'],
    ['hello',       'Hello!', null],
    ['math',        'What is 2+2?', null],
    ['joke',        'Tell me a short joke', null],
];

echo "TOOL-USE ROUTING EVAL (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
printf("cases: %d   passes: %d   threshold: %.0f%%\n", count($cases), $passes, 100 * $threshold);

$totals = [];
$grandHits = 0;
$grandTotal = 0;
$extraToolDecisions = 0;

for ($p = 1; $p <= $passes; $p++) {
    echo "\n--- PASS {$p} ---\n";
    foreach ($cases as [$label, $query, $expect]) {
        $messages = [
            ['role' => 'system', 'content' => $pas->buildSystemPrompt($query) . $pas->dateContextLine()],
            ['role' => 'user', 'content' => $query],
        ];
        $emit = function (string $event, array $data = []) {
        };

        try {
            $result = $firstPass->invoke($cm, $messages, $emit);
        } catch (\Throwable $e) {
            printf("  [ERR] %-16s %s\n", $label, $e->getMessage());
            $grandTotal++;
            continue;
        }

        $selected = [];
        $creates = [];
        foreach (($result['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            $name = $fn['name'] ?? '';
            if ($name !== '') {
                $selected[] = $name;
            }
            if ($name === 'create_calendar_task') {
                $creates[] = json_decode($fn['arguments'] ?? '{}', true) ?: [];
            }
        }

        $ok = false;
        if ($expect === null) {
            $ok = empty($selected);
        } elseif ($expect === 'create_calendar_task') {
            $ok = !empty($creates);
            foreach ($creates as $a) {
                if (trim((string) ($a['content'] ?? '')) === '') {
                    $ok = false;
                }
            }
        } else {
            $ok = in_array($expect, $selected, true);
        }

        $key = $expect ?? 'no_tool';
        $totals[$key] = $totals[$key] ?? [0, 0];
        $totals[$key][1]++;
        if ($ok) {
            $totals[$key][0]++;
            $grandHits++;
        }
        $grandTotal++;

        if (count(array_unique($selected)) > 1) {
            $extraToolDecisions++;
        }

        $got = empty($selected) ? '(none)' : implode(',', $selected);
        printf("  [%s] %-12s want=%-22s got=%s\n", $ok ? 'OK ' : 'XX ', $label, $expect ?? 'no_tool', $got);
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "ACCURACY BY TOOL\n";
foreach ($totals as $tool => [$hits, $cnt]) {
    printf("  %-22s %d/%d   %.0f%%\n", $tool, $hits, $cnt, 100 * $hits / $cnt);
}

$acc = $grandTotal > 0 ? $grandHits / $grandTotal : 0.0;
printf("\nOVERALL: %d/%d = %.1f%%  (threshold %.0f%%)\n", $grandHits, $grandTotal, 100 * $acc, 100 * $threshold);
if ($extraToolDecisions > 0) {
    printf("NOTE: %d decision(s) emitted more than one DISTINCT tool (over-fetching)\n", $extraToolDecisions);
}

$pass = $acc >= $threshold;
echo $pass ? "PASS\n" : "FAIL (below threshold)\n";

if ($withExec) {
    $execOk = runExecution($db, $agent);
    $pass = $pass && $execOk;
}

echo "\n";
exit($pass ? 0 : 1);

/**
 * Real Todoist write path: clear create, duplicate/conflict, cleanup.
 * Returns true when all checks pass.
 */
function runExecution(Database $db, AgentManager $agent): bool
{
    echo "\n--- EXECUTION (REAL Todoist: create + conflict + delete) ---\n";

    if (empty(Config::get('TODOIST_API_KEY'))) {
        echo "SKIPPED: TODOIST_API_KEY not configured.\n";
        return true;
    }

    $pass = 0;
    $fail = 0;
    $check = function (string $label, bool $cond) use (&$pass, &$fail) {
        printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
        $cond ? $pass++ : $fail++;
    };

    $tes = new ToolExecutionService($db, $agent, Config::getProjectRoot() . '/uploads/');
    $events = [];
    $emit = function (string $event, array $data = []) use (&$events) {
        $events[] = ['event' => $event, 'data' => $data];
    };

    $content = 'Localsy e2e test ' . date('His');
    $due = 'tomorrow 9:00';

    echo "B1 clear create: \"{$content}\" due=\"{$due}\"\n";
    try {
        $r1 = $tes->executeToolByName('create_calendar_task', ['content' => $content, 'due_string' => $due], 0, $emit);
    } catch (\Throwable $e) {
        echo '  ERROR: ' . $e->getMessage() . "\n";
        $fail++;
        return $fail === 0;
    }
    $check('tool reported successful create', stripos($r1, 'successfully created') !== false);

    $resp = $tes->makeTodoistRequest('GET', '/tasks');
    $tasks = $resp['results'] ?? (is_array($resp) ? $resp : []);
    $created = null;
    foreach ($tasks as $t) {
        if (($t['content'] ?? '') === $content) {
            $created = $t;
            break;
        }
    }
    $check('GET /tasks found the created task', $created !== null);

    $createdTasks = $tes->getCreatedTasks();
    $check('exactly one task recorded', count($createdTasks) === 1);
    $d = $createdTasks[0] ?? [];
    $check('recorded content matches', ($d['content'] ?? '') === $content);
    $check('recorded due present', !empty($d['due'] ?? null));
    $check('recorded url present', !empty($d['url'] ?? null));

    echo "\nB2 duplicate create (same content+due):\n";
    $r2 = $tes->executeToolByName('create_calendar_task', ['content' => $content, 'due_string' => $due], 0, $emit);
    $check('conflict detected', stripos($r2, 'conflict') !== false);
    $check('[TodoistSuggest: card present', strpos($r2, '[TodoistSuggest:') !== false);

    $resp2 = $tes->makeTodoistRequest('GET', '/tasks');
    $tasks2 = $resp2['results'] ?? (is_array($resp2) ? $resp2 : []);
    $count = 0;
    foreach ($tasks2 as $t) {
        if (($t['content'] ?? '') === $content) {
            $count++;
        }
    }
    $check('exactly one task (no duplicate)', $count === 1);
    $check('no task recorded on conflict', count($tes->getCreatedTasks()) === 1);

    if ($created !== null) {
        $del = $tes->makeTodoistRequest('DELETE', '/tasks/' . $created['id']);
        echo "\nB3 cleanup: DELETE /tasks/{$created['id']} -> " . json_encode($del) . "\n";
        $resp3 = $tes->makeTodoistRequest('GET', '/tasks');
        $tasks3 = $resp3['results'] ?? (is_array($resp3) ? $resp3 : []);
        $still = false;
        foreach ($tasks3 as $t) {
            if (($t['id'] ?? '') === $created['id']) {
                $still = true;
                break;
            }
        }
        $check('test task removed', !$still);
    }

    echo "\nEXECUTION: {$pass} passed, {$fail} failed\n";
    return $fail === 0;
}
