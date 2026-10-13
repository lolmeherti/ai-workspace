<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enums\Tool;
use App\Services\ToolExecutionService;
use App\Services\Tools\CalculateTool;

/**
 * Deterministic coverage for the calculate tool: the owner's acceptance table, its error codes, and the two wiring
 * points that would otherwise fail silently (the Tool enum case, and the pre-dispatch argument exemption).
 *
 * No LLM. Arithmetic is bcmath (exact decimal), so the checks that need it are reported as SKIPPED — loudly — while the
 * image lacks the extension; date arithmetic and every validation path run either way.
 *
 * See `.hermes/plans/tools/2026-10-11_calculator-tool.md`.
 */
class CalculatorTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private array $failures = [];

    private CalculateTool $tool;

    public function __construct()
    {
        $this->tool = new CalculateTool();
    }

    public function run(): bool
    {
        echo "\n-- the owner's acceptance table --\n";

        $this->assertResult('days_between 2026-10-11 → 2026-11-15', ['operation' => 'days_between', 'from' => '2026-10-11', 'to' => '2026-11-15'], '35');
        $this->assertResult('days_between reversed flips the sign', ['operation' => 'days_between', 'from' => '2026-11-15', 'to' => '2026-10-11'], '-35');
        $this->assertResult('date_add 2026-01-31 +1 month clamps', ['operation' => 'date_add', 'date' => '2026-01-31', 'months' => 1], '2026-02-28', ['clamped' => true]);
        $this->assertResult('date_add 2024-01-31 +1 month clamps in a leap year', ['operation' => 'date_add', 'date' => '2024-01-31', 'months' => 1], '2024-02-29', ['clamped' => true]);

        $this->assertResult('add 0.10 + 0.20 = 0.30 (the float trap)', ['operation' => 'add', 'values' => ['0.10', '0.20']], '0.30', null, true);
        $this->assertResult('percent_change 150.00 −20% = 120.00', ['operation' => 'percent_change', 'value' => '150.00', 'percent' => '20', 'direction' => 'decrease'], '120.00', null, true);
        $this->assertResult('percent_of 150.00 by 20% = 30.00', ['operation' => 'percent_of', 'value' => '150.00', 'percent' => '20'], '30.00', null, true);
        $this->assertResult('total 19.99 + 0.01 = 20.00', ['operation' => 'total', 'values' => ['19.99', '0.01'], 'precision' => 2], '20.00', null, true);
        $this->assertResult('divide 1 / 3 at scale 12', ['operation' => 'divide', 'values' => ['1', '3']], '0.333333333333', null, true);
        $this->assertResult('subtract and multiply', ['operation' => 'subtract', 'values' => ['10', '0.10']], '9.90', null, true);

        echo "\n-- the error codes are a closed set, and each one is reachable --\n";
        $this->assertError('an operation the model invented is rejected', ['operation' => 'evaluate', 'values' => ['1']], 'unknown_operation');
        $this->assertError('an undeclared argument is rejected, never ignored', ['operation' => 'add', 'values' => ['1', '2'], 'code' => 'echo 1;'], 'unknown_argument');
        $this->assertError('a bare add with no values', ['operation' => 'add'], 'missing_argument');
        $this->assertError('total without a precision (money never inherits a default)', ['operation' => 'total', 'values' => ['1.00']], 'missing_argument');
        $this->assertError('exponent notation is not a decimal string', ['operation' => 'add', 'values' => ['1e3', '2']], 'invalid_number');
        $this->assertError('a locale separator is not a decimal string', ['operation' => 'add', 'values' => ['1.234,56', '2']], 'invalid_number');
        $this->assertError('a date that does not exist is not rolled forward', ['operation' => 'days_between', 'from' => '2026-02-30', 'to' => '2026-03-01'], 'invalid_date');
        $this->assertError('percent_change without a direction', ['operation' => 'percent_change', 'value' => '10', 'percent' => '5'], 'invalid_direction');
        $this->assertError('precision above the ceiling', ['operation' => 'add', 'values' => ['1', '2'], 'precision' => 99], 'precision_out_of_range');
        $this->assertError('more values than the bound allows', ['operation' => 'add', 'values' => array_fill(0, 21, '1')], 'too_many_values');
        $this->assertError('two unit arguments at once', ['operation' => 'date_add', 'date' => '2026-01-31', 'days' => 1, 'months' => 1], 'missing_argument');
        $this->assertError('an unknown rounding mode', ['operation' => 'total', 'values' => ['1'], 'precision' => 2, 'rounding' => 'half-even'], 'invalid_rounding');
        $this->assertError('divide by zero is refused before any division', ['operation' => 'divide', 'values' => ['1', '0']], 'division_by_zero');

        echo "\n-- nothing throws out of the tool --\n";
        $garbage = $this->tool->execute(['operation' => ['not', 'a', 'string']], 0, [], null, '');
        $this->test('an array as the operation name is an error, not a fatal', str_contains($garbage, '"ok":false'));

        echo "\n-- the two wiring points that fail silently --\n";
        $this->test('calculate is a Tool enum case', Tool::tryFrom('calculate') !== null);
        $this->test('a calculate call passes the pre-dispatch argument filter', ToolExecutionService::hasExecutableArguments('calculate', ['operation' => 'add', 'values' => ['1', '2']]));
        $this->test('and a call with no queries of any kind still passes it', ToolExecutionService::hasExecutableArguments('calculate', ['operation' => 'days_between']));

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d skipped, %d total\n", $this->passed, $this->failed, $this->skipped, $this->passed + $this->failed + $this->skipped);
        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
                if (isset($f['expected'])) {
                    echo '      expected ' . var_export($f['expected'], true) . ', got ' . var_export($f['actual'], true) . "\n";
                }
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return $this->failed === 0;
    }

    /** @param array<string,mixed> $args @param array<string,mixed>|null $extra */
    private function assertResult(string $label, array $args, string $expected, ?array $extra = null, bool $needsBcmath = false): void
    {
        if ($needsBcmath && !extension_loaded('bcmath')) {
            $this->skip($label . ' [bcmath]');

            return;
        }
        $payload = json_decode((string)$this->tool->execute($args, 0, [], null, ''), true);
        if (!is_array($payload)) {
            $this->test($label . ' [the tool answered invalid JSON]', false);

            return;
        }
        $ok = ($payload['result'] ?? null) === $expected;
        foreach (($extra ?? []) as $key => $value) {
            $ok = $ok && (($payload[$key] ?? null) === $value);
        }
        $this->record($label, $ok, $expected, $payload['result'] ?? null, $payload);
    }

    /** @param array<string,mixed> $args */
    private function assertError(string $label, array $args, string $expectedCode): void
    {
        // Every one of these is decided by validation, before any arithmetic: they run whatever the image has.
        $payload = json_decode((string)$this->tool->execute($args, 0, [], null, ''), true);
        $ok = is_array($payload) && ($payload['ok'] ?? null) === false && ($payload['error'] ?? null) === $expectedCode;
        $this->record($label, $ok, $expectedCode, is_array($payload) ? ($payload['error'] ?? null) : 'invalid JSON', $payload ?? []);
    }

    private function test(string $label, bool $ok): void
    {
        $this->record($label, $ok, null, null, []);
    }

    private function skip(string $label): void
    {
        echo "  [SKIP] {$label} — bcmath is not in the image yet (Dockerfile: rebuild pending)\n";
        $this->skipped++;
    }

    private function record(string $label, bool $ok, mixed $expected, mixed $actual, array $payload): void
    {
        echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n";
        if ($ok) {
            $this->passed++;
            if (($payload['precision'] ?? null) !== null) {
                echo "      precision={$payload['precision']} rounding=" . (string)($payload['rounding'] ?? '') . "\n";
            }

            return;
        }
        $this->failed++;
        $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual, 'payload' => $payload];
    }
}
