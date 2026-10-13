<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Logger;
use App\Services\ToolExecutionService;

/**
 * Tool-call argument normalisation.
 *
 * The regression these pin: a model emitting `queries` as a JSON *string* rather than the array its schema
 * declares used to reach implode() and throw a TypeError. A TypeError inside the turn kills the SSE stream
 * before its terminal event, so the browser could only report "Connection ended before completion" with no
 * cause. Two live sites depended on that string being an array (ChatManager and ToolExecutionService), so
 * the fix normalises once where arguments enter the tool layer.
 */
class ToolArgumentTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(private Database $db)
    {
        Logger::setDatabase($db);
        // $modelName is a process-global set when any earlier suite constructs an AgentManager. Left set,
        // this test's warn rows would be attributed to a real model and inflate the /models.php report.
        Logger::clearModelName();
    }

    public function run(): bool
    {
        echo "\n=== Tool argument normalisation ===\n";

        $tool = 'normalize_test_tool';

        // ── the declared shape passes through untouched ─────────────────────
        $args = ToolExecutionService::normalizeToolArguments(['queries' => ['a', 'b']], $tool);
        $this->testEq('an array is left exactly as it was', ['a', 'b'], $args['queries']);
        $args = ToolExecutionService::normalizeToolArguments(['content' => 'x'], $tool);
        $this->test('arguments without a queries key are untouched', $args === ['content' => 'x']);

        // ── the shapes a model actually emits ───────────────────────────────
        $this->testEq('a bare string becomes one query', ['what is X'],
            ToolExecutionService::normalizeToolArguments(['queries' => 'what is X'], $tool)['queries']);
        $this->testEq('a JSON array string is decoded', ['a', 'b'],
            ToolExecutionService::normalizeToolArguments(['queries' => '["a","b"]'], $tool)['queries']);
        $this->testEq('a JSON scalar string is decoded, not wrapped in quotes', ['one'],
            ToolExecutionService::normalizeToolArguments(['queries' => '"one"'], $tool)['queries']);
        $this->testEq('an integer becomes a string query', ['7'],
            ToolExecutionService::normalizeToolArguments(['queries' => 7], $tool)['queries']);
        $this->testEq('an empty string becomes no queries', [],
            ToolExecutionService::normalizeToolArguments(['queries' => ''], $tool)['queries']);
        $this->testEq('null becomes no queries', [],
            ToolExecutionService::normalizeToolArguments(['queries' => null], $tool)['queries']);
        $this->testEq('empty and non-string entries are dropped', ['a', '5'],
            ToolExecutionService::normalizeToolArguments(['queries' => ['a', '', '   ', 5]], $tool)['queries']);

        // ── the regression: the exact input that killed the turn ────────────
        $decoded = json_decode('{"queries":"a plain query string","tool":"search_memories"}', true);
        $normalized = ToolExecutionService::normalizeToolArguments($decoded, 'search_memories');
        $threw = false;
        $joined = '';
        try {
            $joined = implode(', ', $normalized['queries']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->test('the payload that crashed the turn no longer throws', !$threw);
        $this->testEq('and it still produces the query the model meant', 'a plain query string', $joined);

        // ── the violation is observable, at a level the log viewer shows ────
        $where = "event_type = 'tool_args_normalized' AND context LIKE '%{$tool}%'";
        $before = (int) ($this->db->query("SELECT COUNT(*) c FROM app_events WHERE {$where}")[0]['c'] ?? 0);
        ToolExecutionService::normalizeToolArguments(['queries' => 'a query'], $tool);
        ToolExecutionService::normalizeToolArguments(['queries' => ['already', 'a', 'list']], $tool);
        $after = (int) ($this->db->query("SELECT COUNT(*) c FROM app_events WHERE {$where}")[0]['c'] ?? 0);
        $this->testEq('a coerced argument is logged once', $before + 1, $after);

        $row = $this->db->query("SELECT level, source, message, model FROM app_events WHERE {$where} ORDER BY id DESC LIMIT 1")[0] ?? [];
        $this->testEq('logged at warn, so /logs shows it by default', 'warn', $row['level'] ?? '');
        $this->test('and carries no model attribution (it is not a model fault)', ($row['model'] ?? '') === '');

        // Don't leave probe rows behind: they are test noise on a user-facing report.
        $this->db->query("DELETE FROM app_events WHERE {$where}");
        $left = (int) ($this->db->query("SELECT COUNT(*) c FROM app_events WHERE {$where}")[0]['c'] ?? 0);
        $this->testEq('probe rows are cleaned up after the run', 0, $left);

        // ── what counts as an executable call ───────────────────────────────
        // The browsing tool is called with NO arguments on purpose (that call lists what the stored
        // sources contain), so a guard that demanded queries would silently discard it — the model would
        // look like it never tried. This is the regression that guard exists to prevent.
        $this->test('a bare browsing call is executable',
            ToolExecutionService::hasExecutableArguments('search_session_evidence', []));
        $this->test('a browsing call with only ids is executable',
            ToolExecutionService::hasExecutableArguments('search_session_evidence', ['ids' => ['S1-C4']]));
        $this->test('an empty search_web call is not executable',
            !ToolExecutionService::hasExecutableArguments('search_web', []));
        $this->test('and neither is one with blank queries',
            !ToolExecutionService::hasExecutableArguments('search_web', ['queries' => ['', '  ']]));
        $this->test('a search_web call with a real query is executable',
            ToolExecutionService::hasExecutableArguments('search_web', ['queries' => ['x']]));
        $this->test('the calendar tool validates its own content',
            ToolExecutionService::hasExecutableArguments('create_calendar_task', []));

        echo "\n--- ToolArgument: {$this->passed} passed, {$this->failed} failed ---\n";
        foreach ($this->failures as $f) echo "  FAIL: {$f}\n";
        return $this->failed === 0;
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $this->failures[] = "{$label}: expected {$e}, got {$a}";
        echo "  [FAIL] {$label} — expected {$e}, got {$a}\n";
    }
}
