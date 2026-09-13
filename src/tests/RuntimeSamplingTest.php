<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\ChatManager;
use ReflectionClass;
use ReflectionMethod;

/**
 * Deterministic tests for the per-mode sampling + reasoning-policy translation
 * (runtime adapters, request side) and the answer-pass output budget. No LLM
 * calls. Uses reflection to exercise AgentManager/ChatManager private methods.
 */
class RuntimeSamplingTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    /** @var array<string, mixed> */
    private array $savedEnv = [];

    public function run(): bool
    {
        $this->runWritePath();
        $this->runResolveSampling();
        $this->runApplyReasoning();
        $this->runAnswerBudget();

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
            $this->failures[] = ['label' => $label];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $this->savedEnv[$key] = $_ENV[$key] ?? null;
        $_ENV[$key] = $value;
    }

    private function restoreEnv(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->savedEnv = [];
    }

    // ===================================================================
    // writePath — generic dotted-path setter
    // ===================================================================
    private function runWritePath(): void
    {
        echo "\n=== writePath ===\n";

        $method = new ReflectionMethod(AgentManager::class, 'writePath');
        $method->setAccessible(true);

        // Top-level key.
        $arr = [];
        $method->invokeArgs(null, [&$arr, 'reasoning_effort', 'none']);
        $this->testEq('top-level key written', ['reasoning_effort' => 'none'], $arr);

        // Nested dotted path.
        $arr = [];
        $method->invokeArgs(null, [&$arr, 'chat_template_kwargs.enable_thinking', false]);
        $this->testEq(
            'nested path built',
            ['chat_template_kwargs' => ['enable_thinking' => false]],
            $arr
        );

        // Nested path preserves siblings.
        $arr = ['chat_template_kwargs' => ['existing' => 1]];
        $method->invokeArgs(null, [&$arr, 'chat_template_kwargs.enable_thinking', false]);
        $this->testEq(
            'nested path preserves siblings',
            ['chat_template_kwargs' => ['existing' => 1, 'enable_thinking' => false]],
            $arr
        );
    }

    // ===================================================================
    // resolveSampling — mode -> temperature + sampling params
    // ===================================================================
    private function runResolveSampling(): void
    {
        echo "\n=== resolveSampling ===\n";

        $agent = new AgentManager();
        $method = new ReflectionMethod(AgentManager::class, 'resolveSampling');
        $method->setAccessible(true);

        $this->setEnv('LLM_SAMPLING', '{"thinking":{"temperature":1.0,"top_p":0.95,"top_k":20},"instruct":{"temperature":0.7,"top_p":0.8,"top_k":20}}');
        $this->setEnv('DEFAULT_CHAT_TEMP', '1');

        [$temp, $sampling] = $method->invoke($agent, 'thinking', null);
        $this->testEq('thinking temperature', 1.0, $temp);
        $this->testEq('thinking top_k', 20, $sampling['top_k'] ?? null);

        [$temp, $sampling] = $method->invoke($agent, 'instruct', null);
        $this->testEq('instruct temperature', 0.7, $temp);
        $this->testEq('instruct top_p', 0.8, $sampling['top_p'] ?? null);

        // Explicit per-task temperature always wins.
        [$temp] = $method->invoke($agent, 'instruct', 0.3);
        $this->testEq('explicit temperature wins', 0.3, $temp);

        // null mode defaults to thinking.
        [$temp] = $method->invoke($agent, null, null);
        $this->testEq('null mode defaults to thinking', 1.0, $temp);

        // Missing sampling JSON -> global default, empty params.
        $this->setEnv('LLM_SAMPLING', '');
        [$temp, $sampling] = $method->invoke($agent, null, null);
        $this->testEq('no sampling JSON -> DEFAULT_CHAT_TEMP', 1.0, $temp);
        $this->testEq('no sampling JSON -> empty params', [], $sampling);

        $this->restoreEnv();
    }

    // ===================================================================
    // applyReasoning — runtime policy field/path + off_value
    // ===================================================================
    private function runApplyReasoning(): void
    {
        echo "\n=== applyReasoning ===\n";

        $agent = new AgentManager();
        $method = new ReflectionMethod(AgentManager::class, 'applyReasoning');
        $method->setAccessible(true);

        $this->setEnv('LLM_RUNTIME_POLICY', '{"reasoning":{"field":"reasoning_effort","off_value":"none"}}');

        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'instruct', null]);
        $this->testEq('instruct writes off_value', ['reasoning_effort' => 'none'], $payload);

        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'thinking', null]);
        $this->testEq('thinking with no effort writes nothing', [], $payload);

        // Dotted-path field (e.g. chat_template_kwargs.enable_thinking) with bool off_value.
        $this->setEnv('LLM_RUNTIME_POLICY', '{"reasoning":{"field":"chat_template_kwargs.enable_thinking","off_value":false}}');
        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'instruct', null]);
        $this->testEq(
            'dotted field with bool off_value',
            ['chat_template_kwargs' => ['enable_thinking' => false]],
            $payload
        );

        // Graduated effort map (only reachable when a runtime declares one).
        $this->setEnv('LLM_RUNTIME_POLICY', '{"reasoning":{"field":"reasoning_effort","off_value":"none","default_effort":"medium","effort_map":{"low":"low","medium":"medium","high":"xhigh"}}}');
        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'thinking', 'high']);
        $this->testEq('explicit effort maps through', ['reasoning_effort' => 'xhigh'], $payload);

        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'thinking', null]);
        $this->testEq('default effort applied when none passed', ['reasoning_effort' => 'medium'], $payload);

        // qwen38 shape: dotted field + string off_value + effort_map + default.
        $this->setEnv('LLM_RUNTIME_POLICY', '{"reasoning":{"field":"chat_template_kwargs.reasoning_effort","off_value":"none","default_effort":"medium","effort_map":{"low":"low","medium":"medium","high":"xhigh"}}}');
        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'instruct', null]);
        $this->testEq('qwen38 instruct -> off via dotted field', ['chat_template_kwargs' => ['reasoning_effort' => 'none']], $payload);

        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'thinking', 'high']);
        $this->testEq('qwen38 high -> xhigh via dotted field', ['chat_template_kwargs' => ['reasoning_effort' => 'xhigh']], $payload);

        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'thinking', null]);
        $this->testEq('qwen38 default -> medium via dotted field', ['chat_template_kwargs' => ['reasoning_effort' => 'medium']], $payload);

        // No field configured -> no-op, even in instruct mode.
        $this->setEnv('LLM_RUNTIME_POLICY', '{"reasoning":{}}');
        $payload = [];
        $method->invokeArgs($agent, [&$payload, 'instruct', null]);
        $this->testEq('empty policy -> no-op', [], $payload);

        $this->restoreEnv();
    }

    // ===================================================================
    // resolveAnswerMaxTokens — answer-pass budget (Part B)
    // ===================================================================
    private function runAnswerBudget(): void
    {
        echo "\n=== resolveAnswerMaxTokens ===\n";

        $this->setEnv('LLM_REASONING_BUDGET', '4096');
        $this->setEnv('LLM_CTX_SIZE', '128000');

        $ref = new ReflectionClass(ChatManager::class);
        $cm = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('countTokens');
        $prop->setAccessible(true);
        $prop->setValue($cm, fn(string $s): int => strlen($s));
        $method = $ref->getMethod('resolveAnswerMaxTokens');
        $method->setAccessible(true);

        // No prompt -> max(8192, 4096+4096) = 8192.
        $this->testEq('empty prompt -> floor of 8192', 8192, $method->invoke($cm, []));

        // Small prompt under headroom -> unchanged.
        $this->testEq('small prompt keeps floor', 8192, $method->invoke($cm, [
            ['content' => str_repeat('a', 1000)],
        ]));

        // Large prompt clamps by headroom: 128000 - 120000 - 256 = 7744.
        $this->testEq('large prompt clamps by headroom', 7744, $method->invoke($cm, [
            ['content' => str_repeat('a', 120000)],
        ]));

        // No reasoning budget -> still max(8192, 0+4096) = 8192.
        $this->setEnv('LLM_REASONING_BUDGET', '0');
        $this->testEq('zero reasoning budget -> floor 8192', 8192, $method->invoke($cm, []));

        // No ctx size -> no clamp.
        $this->setEnv('LLM_CTX_SIZE', '0');
        $this->testEq('no ctx size -> no clamp', 8192, $method->invoke($cm, [
            ['content' => str_repeat('a', 1000000)],
        ]));

        $this->restoreEnv();
    }
}
