<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\ToolExecutionService;

class ParserTest
{
    private ToolExecutionService $service;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(ToolExecutionService $service)
    {
        $this->service = $service;
    }

    public function run(): bool
    {
        $this->runMatchToolName();
        $this->runMatchAllToolNames();
        $this->runExtractColon();
        $this->runExtractFuncCall();

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

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($this->service, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->service, ...$args);
    }

    private function test(string $label, mixed $expected, mixed $actual): void
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

    // ===================================================================
    // matchToolName — full-response scan with delimiter validation
    // ===================================================================
    private function runMatchToolName(): void
    {
        echo "\n=== matchToolName ===\n";

        $this->test('position 0: search_files', 'search_files',
            $this->invokePrivate('matchToolName', 'search_files QUERY:cv'));

        $this->test('position 0: search_web', 'search_web',
            $this->invokePrivate('matchToolName', 'search_web QUERY:stock price NVDA'));

        $this->test('position 0: search_memories', 'search_memories',
            $this->invokePrivate('matchToolName', 'search_memories QUERY:IBAN account'));

        $this->test('position 0: get_todoist_tasks', 'get_todoist_tasks',
            $this->invokePrivate('matchToolName', 'get_todoist_tasks QUERY:flight'));

        $this->test('position 0: create_todoist_task', 'create_todoist_task',
            $this->invokePrivate('matchToolName', 'create_todoist_task QUERY:buy milk DUE_STRING:tomorrow at 9am'));

        $this->test('position 0: update_todoist_task', 'update_todoist_task',
            $this->invokePrivate('matchToolName', 'update_todoist_task QUERY:dentist NEW_CONTENT:call Dr. Smith NEW_DUE_STRING:Friday 3pm'));

        $this->test('position 0: delete_todoist_task', 'delete_todoist_task',
            $this->invokePrivate('matchToolName', 'delete_todoist_task QUERY:old reminders'));

        $this->test('position 0: get_email_briefing', 'get_email_briefing',
            $this->invokePrivate('matchToolName', 'get_email_briefing'));

        $this->test('trailing punctuation after params', 'search_files',
            $this->invokePrivate('matchToolName', 'search_files QUERY:cv.'));

        $this->test('tool name then newline', 'search_files',
            $this->invokePrivate('matchToolName', "search_files QUERY:cv\nMore text here"));

        $this->test('empty response', null,
            $this->invokePrivate('matchToolName', ''));

        $this->test('plain greeting — no tool', null,
            $this->invokePrivate('matchToolName', 'Hey there! How can I help you?'));

        $this->test('super_abilities request — no tool', null,
            $this->invokePrivate('matchToolName', 'In order to help you with that, I need super_abilities.'));

        $this->test('natural summary — no tool', null,
            $this->invokePrivate('matchToolName', 'I found your flight details! You have a round trip with TAP Air Portugal.'));

        $this->test('tool name mid-sentence — scan finds it', 'search_files',
            $this->invokePrivate('matchToolName', 'I will use search_files QUERY:cv to find it'));

        $this->test('tool name after newline — scan finds it', 'search_files',
            $this->invokePrivate('matchToolName', "\nsearch_files QUERY:cv"));

        $this->test('tool name after natural prefix — scan finds it', 'search_files',
            $this->invokePrivate('matchToolName', 'Sure! search_files QUERY:cv'));

        $this->test('leading space — scan finds it', 'search_files',
            $this->invokePrivate('matchToolName', ' search_files QUERY:cv'));

        $this->test('leading carriage return — scan finds it', 'search_files',
            $this->invokePrivate('matchToolName', "\rsearch_files QUERY:cv"));

        $this->test('leading BOM-like chars — scan finds tool after BOM', 'search_files',
            $this->invokePrivate('matchToolName', "\xEF\xBB\xBFsearch_files QUERY:cv"));

        $this->test('tool name with no space (concatenated) — no delimiter, no match', null,
            $this->invokePrivate('matchToolName', 'search_filesQUERY:cv'));

        $this->test('tool name then tab — disallowed delimiter', null,
            $this->invokePrivate('matchToolName', "search_files\tQUERY:cv"));

        $this->test('tool name then colon immediately', 'search_files',
            $this->invokePrivate('matchToolName', 'search_files:QUERY:cv'));

        $this->test('tool name then open paren (function-call)', 'search_files',
            $this->invokePrivate('matchToolName', 'search_files(QUERY="cv")'));

        $this->test('calendar is not a Tool enum', null,
            $this->invokePrivate('matchToolName', 'calendar'));

        $this->test('longest-first ordering: search_memories over search_files substring', 'search_memories',
            $this->invokePrivate('matchToolName', 'search_memories QUERY:test'));

        $this->test('scan limitation: "notsearch_files" matches "search_files" at offset 3', 'search_files',
            $this->invokePrivate('matchToolName', 'notsearch_files QUERY:cv'));

        $this->test('long tool name followed by digit — disallowed delimiter', null,
            $this->invokePrivate('matchToolName', 'search_files2 QUERY:cv'));

        $this->test('update before delete — both 7-char prefixes but different', 'update_todoist_task',
            $this->invokePrivate('matchToolName', 'update_todoist_task QUERY:test'));

        $this->test('delete before update — different prefix', 'delete_todoist_task',
            $this->invokePrivate('matchToolName', 'delete_todoist_task QUERY:test'));

        $this->test('model hallucination: get_calendar_events is not an enum', null,
            $this->invokePrivate('matchToolName', 'get_calendar_events QUERY:tomorrow'));
    }

    // ===================================================================
    // matchAllToolNames — multi-tool detection
    // ===================================================================
    private function runMatchAllToolNames(): void
    {
        echo "\n=== matchAllToolNames ===\n";

        // Helper: extract names from matchAllToolNames result
        $names = fn(string $response): array => array_column(
            $this->invokePrivate('matchAllToolNames', $response), 'name'
        );

        $this->test('single tool', ['search_files'],
            $names('search_files QUERY:cv'));

        $this->test('two tools space-separated', ['search_files', 'search_web'],
            $names('search_files QUERY:cv search_web QUERY:stock price'));

        $this->test('two tools with text between', ['search_files', 'search_web'],
            $names('search_files QUERY:invoice and search_web QUERY:latest news'));

        $this->test('three tools', ['search_files', 'search_web', 'search_memories'],
            $names('search_files QUERY:cv search_web QUERY:stocks search_memories QUERY:IBAN'));

        $this->test('duplicate tool — only first kept', ['search_files'],
            $names('search_files QUERY:cv search_files QUERY:resume'));

        $this->test('function-call format detected', ['search_files', 'search_web'],
            $names('search_files(QUERY="cv") search_web(QUERY="stocks")'));

        $this->test('calendar is not an enum — returns empty', [],
            $names('calendar QUERY:next week'));

        $this->test('empty response', [],
            $names(''));

        $this->test('plain text — no tools', [],
            $names('Hey there! How can I help you?'));

        $this->test('concatenated name rejected', [],
            $names('search_filesQUERY:cv'));

        $this->test('names in occurrence order', ['search_web', 'search_files'],
            $names('search_web QUERY:stocks then search_files QUERY:report'));
    }

    // ===================================================================
    // extractParams — colon format (KEY:VALUE KEY:VALUE)
    // ===================================================================
    private function runExtractColon(): void
    {
        echo "\n=== extractParams (colon format) ===\n";

        $this->test('search_files: single QUERY', ['query' => 'cv resume'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:cv resume'));

        $this->test('search_files: QUERY with special chars', ['query' => 'NVIDIA stock $150 !'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:NVIDIA stock $150 !'));

        $this->test('search_web: multi-word QUERY', ['query' => 'latest ceasefire news'],
            $this->invokePrivate('extractParams', 'search_web', 'search_web QUERY:latest ceasefire news'));

        $this->test('search_memories: QUERY with underscores', ['query' => 'IBAN_account_number'],
            $this->invokePrivate('extractParams', 'search_memories', 'search_memories QUERY:IBAN_account_number'));

        $this->test('get_todoist_tasks: QUERY only', ['query' => 'find flights'],
            $this->invokePrivate('extractParams', 'get_todoist_tasks', 'get_todoist_tasks QUERY:find flights'));

        $this->test('delete_todoist_task: QUERY only', ['query' => 'old reminders'],
            $this->invokePrivate('extractParams', 'delete_todoist_task', 'delete_todoist_task QUERY:old reminders'));

        $this->test('create_todoist_task: QUERY + DUE_STRING', ['query' => 'buy milk', 'due_string' => 'tomorrow at 9am'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task QUERY:buy milk DUE_STRING:tomorrow at 9am'));

        $this->test('create_todoist_task: QUERY with semicolons, DUE_STRING', ['query' => 'call bank; check balance', 'due_string' => 'Monday'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task QUERY:call bank; check balance DUE_STRING:Monday'));

        $this->test('create_todoist_task: DUE_STRING with commas and time', ['query' => 'dentist', 'due_string' => 'July 15, 2026 at 2:30pm'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task QUERY:dentist DUE_STRING:July 15, 2026 at 2:30pm'));

        $this->test('create_todoist_task: DUE_STRING with colon (time)', ['query' => 'meeting', 'due_string' => 'tomorrow 14:00'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task QUERY:meeting DUE_STRING:tomorrow 14:00'));

        $this->test('update_todoist_task: all three params', [
            'query' => 'dentist appt',
            'new_content' => 'call Dr. Smith',
            'new_due_string' => 'Friday 3pm',
        ], $this->invokePrivate('extractParams', 'update_todoist_task',
            'update_todoist_task QUERY:dentist appt NEW_CONTENT:call Dr. Smith NEW_DUE_STRING:Friday 3pm'));

        $this->test('update_todoist_task: NEW_CONTENT with embedded colons', [
            'query' => 'note',
            'new_content' => 'URL: https://example.com page: 42',
            'new_due_string' => 'today',
        ], $this->invokePrivate('extractParams', 'update_todoist_task',
            'update_todoist_task QUERY:note NEW_CONTENT:URL: https://example.com page: 42 NEW_DUE_STRING:today'));

        $this->test('leading/trailing whitespace trimmed from values', ['query' => 'cv'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:  cv  '));

        $this->test('empty QUERY value', ['query' => ''],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:'));

        $this->test('no matching keys at all — falls back to default empty query', ['query' => ''],
            $this->invokePrivate('extractParams', 'search_files', 'search_files'));

        $this->test('non-ASCII UTF-8: café résumé', ['query' => 'café résumé'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:café résumé'));

        $this->test('non-ASCII UTF-8: München München', ['query' => 'München München'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:München München'));

        $this->test('emoji in QUERY', ['query' => 'flight ✈️ booking'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files QUERY:flight ✈️ booking'));

        $this->test('DUE_STRING with natural language "next Tuesday at noon"', ['query' => 'lunch', 'due_string' => 'next Tuesday at noon'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task QUERY:lunch DUE_STRING:next Tuesday at noon'));

        $this->test('params in reverse order — now fixed: occurrence order respected', [
            'due_string' => 'tomorrow',
            'query' => 'milk',
        ], $this->invokePrivate('extractParams', 'create_todoist_task',
            'create_todoist_task DUE_STRING:tomorrow QUERY:milk'));

        $this->test('extra unknown KEY:VALUE is ignored', ['query' => 'cv'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files EXTRA:ignored QUERY:cv'));
    }

    // ===================================================================
    // extractParams — function-call format (KEY="VALUE", KEY="VALUE")
    // ===================================================================
    private function runExtractFuncCall(): void
    {
        echo "\n=== extractParams (function-call format) ===\n";

        $this->test('func-call: search_files double-quoted', ['query' => 'cv resume'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files(QUERY="cv resume")'));

        $this->test('func-call: search_web double-quoted', ['query' => 'stock price NVDA'],
            $this->invokePrivate('extractParams', 'search_web', 'search_web(QUERY="stock price NVDA")'));

        $this->test('func-call: create_todoist_task double-quoted', ['query' => 'buy milk', 'due_string' => 'tomorrow at 9am'],
            $this->invokePrivate('extractParams', 'create_todoist_task', 'create_todoist_task(QUERY="buy milk" DUE_STRING="tomorrow at 9am")'));

        $this->test('func-call: update_todoist_task all three double-quoted', [
            'query' => 'dentist',
            'new_content' => 'call Dr. Smith',
            'new_due_string' => 'Friday 3pm',
        ], $this->invokePrivate('extractParams', 'update_todoist_task',
            'update_todoist_task(QUERY="dentist" NEW_CONTENT="call Dr. Smith" NEW_DUE_STRING="Friday 3pm")'));

        $this->test('func-call: single-quoted value — escaped quote limitation', ['query' => 'it\\'],
            $this->invokePrivate('extractParams', 'search_files', "search_files(QUERY='it\\'s done')"));

        $this->test('func-call: unquoted value', ['query' => 'cv'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files(QUERY=cv)'));

        $this->test('func-call: unquoted multi-word — greedy capture to closing paren', ['query' => 'cv resume pdf'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files(QUERY=cv resume pdf)'));

        $this->test('func-call: empty parens, no params', ['query' => ''],
            $this->invokePrivate('extractParams', 'search_files', 'search_files()'));

        $this->test('func-call: extra whitespace around = signs', ['query' => 'cv'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files( QUERY = "cv" )'));

        $this->test('func-call: trailing comma after last param', ['query' => 'cv'],
            $this->invokePrivate('extractParams', 'search_files', 'search_files(QUERY="cv",)'));
    }
}
