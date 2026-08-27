<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\Database;
use App\Services\ToolExecutionService;
use App\Services\Tools\CreateTodoistTaskTool;
use App\Services\Tools\TodoistApiClient;

/**
 * Todoist create-tool execution — deterministic (fake HTTP client).
 *
 * Exercises CreateTodoistTaskTool end-to-end without network: the clear-create
 * path (POST + task recording), the conflict path (no POST + card), due
 * normalization, empty-content guard, and the executeToolByName arg plumbing
 * that routes content/due_string through to the tool.
 */
class TodoistCreateToolTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private Database $db;
    private string $uploadDir;

    public function __construct(Database $db, string $uploadDir)
    {
        $this->db = $db;
        $this->uploadDir = $uploadDir;
    }

    public function run(): bool
    {
        echo "\n=== clear create ===\n";
        $this->runClearCreate();

        echo "\n=== conflict (no create) ===\n";
        $this->runConflict();

        echo "\n=== normalization + guard ===\n";
        $this->runNormalization();

        echo "\n=== executeToolByName arg plumbing ===\n";
        $this->runPlumbing();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);

        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    private function tool(FakeTodoistClient $fake): CreateTodoistTaskTool
    {
        return new CreateTodoistTaskTool($this->db, new AgentManager(), $this->uploadDir, $fake);
    }

    private function runClearCreate(): void
    {
        $fake = new FakeTodoistClient();
        $tool = $this->tool($fake);
        $events = [];
        $emit = function (string $event, array $data = []) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        };

        $result = $tool->execute(
            ['content' => 'Buy milk', 'due_string' => 'tomorrow 9:00'],
            0,
            [],
            $emit,
            '{}'
        );

        $this->test('returns success confirmation', stripos($result, 'successfully created') !== false);

        $post = $this->findPost($fake);
        $this->test('POST /tasks issued', $post !== null);
        $this->testEq('POST content correct', 'Buy milk', $post['data']['content'] ?? null);
        $this->testEq('POST due_string correct', 'tomorrow 9:00', $post['data']['due_string'] ?? null);

        $created = $tool->getCreatedTasks();
        $this->testEq('records exactly one created task', 1, count($created));
        $d = $created[0] ?? [];
        $this->testEq('recorded content correct', 'Buy milk', $d['content'] ?? null);
        $this->test('recorded due present', ($d['due'] ?? '') !== '');
        $this->test('recorded url present', ($d['url'] ?? '') !== '');
        $this->testEq('tool no longer emits calendar_task_created (aggregation lives in ChatManager)', 0, count($this->eventsOf($events, 'calendar_task_created')));
    }

    private function runConflict(): void
    {
        $fake = new FakeTodoistClient();
        $fake->tasks = [['content' => 'Buy milk', 'due' => ['datetime' => '2026-09-29T10:00:00']]];
        $tool = $this->tool($fake);
        $events = [];
        $emit = function (string $event, array $data = []) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        };

        $result = $tool->execute(
            ['content' => 'buy milk', 'due_string' => '2026-09-29 10:00'],
            0,
            [],
            $emit,
            '{}'
        );

        $this->test('conflict detected', stripos($result, 'conflict') !== false);
        $this->test('no POST issued on conflict', $this->findPost($fake) === null);
        $this->test('returns [CalendarSuggest: card', strpos($result, '[CalendarSuggest:') !== false);
        $this->testEq('no task recorded on conflict', 0, count($tool->getCreatedTasks()));
    }

    private function runNormalization(): void
    {
        $fake = new FakeTodoistClient();
        $events = [];
        $emit = function (string $event, array $data = []) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        };

        $this->tool($fake)->execute(['content' => 'X', 'due_string' => 'asap'], 0, [], $emit, '{}');
        $post = $this->findPost($fake);
        $this->testEq('asap normalizes to today', 'today', $post['data']['due_string'] ?? null);

        try {
            $this->tool($fake)->execute(['content' => '', 'due_string' => null], 0, [], $emit, '{}');
            $this->test('empty content throws', false);
        } catch (\Throwable $e) {
            $this->test('empty content throws', true);
        }
    }

    private function runPlumbing(): void
    {
        $fake = new FakeTodoistClient();
        $tes = new ToolExecutionService($this->db, new AgentManager(), $this->uploadDir, $fake);
        $events = [];
        $emit = function (string $event, array $data = []) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        };

        $result = $tes->executeToolByName('create_calendar_task', ['content' => 'Call dentist', 'due_string' => 'tomorrow 10:00'], 0, $emit);

        $this->test('plumbing returns success', stripos($result, 'successfully created') !== false);
        $post = $this->findPost($fake);
        $this->testEq('plumbing passes content through', 'Call dentist', $post['data']['content'] ?? null);
        $this->testEq('plumbing passes due_string through', 'tomorrow 10:00', $post['data']['due_string'] ?? null);
    }

    private function findPost(FakeTodoistClient $fake): ?array
    {
        foreach ($fake->requests as $r) {
            if ($r['method'] === 'POST') {
                return $r;
            }
        }
        return null;
    }

    private function eventsOf(array $events, string $type): array
    {
        return array_values(array_filter($events, fn($e) => $e['event'] === $type));
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }
}

/** In-memory Todoist client: records requests, returns canned responses. */
class FakeTodoistClient extends TodoistApiClient
{
    public array $requests = [];
    public array $tasks = [];

    public function request(string $method, string $endpoint, ?array $data = null): array
    {
        $this->requests[] = ['method' => $method, 'endpoint' => $endpoint, 'data' => $data];

        if ($method === 'GET' && $endpoint === '/tasks') {
            return ['results' => $this->tasks];
        }

        if ($method === 'POST' && $endpoint === '/tasks') {
            return [
                'id' => 'T123',
                'content' => $data['content'] ?? '',
                'due' => ['datetime' => '2026-09-29T10:00:00'],
                'url' => 'https://todoist.com/showTask?id=T123',
            ];
        }

        return [];
    }
}
