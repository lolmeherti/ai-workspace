<?php

declare(strict_types=1);

namespace App\Tests;

use App\ChatManager;
use App\Enums\Tool;

/**
 * Tool schema surface — deterministic.
 *
 * Asserts buildToolSchemas() returns a well-formed, correctly-scoped function-
 * calling surface. This is the guard against the tool surface silently
 * regressing (a write tool accidentally exposed, a description dropped, a
 * required parameter removed). No LLM is involved.
 */
class ToolSchemaTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== exposed tool surface ===\n";

        $rc = new \ReflectionClass(ChatManager::class);
        $inst = $rc->newInstanceWithoutConstructor();
        $m = $rc->getMethod('buildToolSchemas');
        $m->setAccessible(true);
        $schemas = $m->invoke($inst);

        $names = [];
        $byName = [];
        foreach ($schemas as $s) {
            $name = $s['function']['name'] ?? '';
            $names[] = $name;
            $byName[$name] = $s['function'];
        }

        $this->testEq('exposes exactly 5 tools', 5, count($names));

        $expected = ['search_local', 'search_web', 'search_calendar', 'search_session_evidence', 'create_calendar_task'];
        $sortedNames = $names;
        $sortedExpected = $expected;
        sort($sortedNames);
        sort($sortedExpected);
        $this->testEq('exposed tool names match the expected set', $sortedExpected, $sortedNames);

        $allValidEnum = true;
        foreach ($names as $n) {
            if (Tool::tryFrom($n) === null) {
                $allValidEnum = false;
                break;
            }
        }
        $this->test('every exposed tool is a valid Tool enum case', $allValidEnum);

        foreach (['update_calendar_task', 'delete_calendar_task', 'get_email_briefing'] as $wt) {
            $this->test("write/exec tool {$wt} is NOT exposed to the model", !in_array($wt, $names, true));
        }

        $allHaveDesc = true;
        foreach ($byName as $fn) {
            if (trim((string) ($fn['description'] ?? '')) === '') {
                $allHaveDesc = false;
                break;
            }
        }
        $this->test('every tool has a non-empty description', $allHaveDesc);

        $create = $byName['create_calendar_task'] ?? null;
        $this->test('create_calendar_task requires content', $create !== null && in_array('content', $create['parameters']['required'] ?? [], true));
        $this->test('create_calendar_task has due_string property', $create !== null && isset($create['parameters']['properties']['due_string']));
        $this->test('create_calendar_task due_string is optional', $create !== null && !in_array('due_string', $create['parameters']['required'] ?? [], true));

        foreach (['search_local', 'search_web', 'search_calendar'] as $st) {
            $fn = $byName[$st] ?? null;
            $this->test("{$st} requires queries", $fn !== null && in_array('queries', $fn['parameters']['required'] ?? [], true));
        }

        // Editor mode: only the memories-only tool is exposed (no files/web/calendar).
        echo "\n=== editor-mode tool surface ===\n";
        $editorSchemas = $m->invoke($inst, true);
        $editorNames = [];
        $editorByName = [];
        foreach ($editorSchemas as $s) {
            $n = $s['function']['name'] ?? '';
            $editorNames[] = $n;
            $editorByName[$n] = $s['function'];
        }
        $this->testEq('editor mode exposes exactly 1 tool', 1, count($editorNames));
        $this->testEq('editor mode exposes search_memories only', ['search_memories'], $editorNames);
        $this->test('editor-mode tool is a valid Tool enum case', Tool::tryFrom($editorNames[0] ?? '') !== null);
        $this->test('editor mode does NOT expose search_local', !in_array('search_local', $editorNames, true));
        $this->test('editor mode does NOT expose search_web', !in_array('search_web', $editorNames, true));
        $sm = $editorByName['search_memories'] ?? null;
        $this->test('search_memories requires queries', $sm !== null && in_array('queries', $sm['parameters']['required'] ?? [], true));
        $this->test('search_memories has non-empty description', $sm !== null && trim((string) ($sm['description'] ?? '')) !== '');

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
