<?php

declare(strict_types=1);

namespace App\Tests;

use App\ChatManager;
use App\Repositories\AppSettingsRepository;
use ReflectionClass;
use ReflectionMethod;

/**
 * Deterministic tests for the reasoning-effort control plumbing: the stored-value
 * -> (mode, effort) mapping and the app_settings repository round-trip. No LLM
 * calls, no DB (the repository is exercised against an in-memory stub).
 */
class ReasoningEffortTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runMapping();
        $this->runRepository();

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
        $this->test($label . ' => ' . var_export($actual, true), $expected == $actual);
    }

    private function runMapping(): void
    {
        echo "\n=== reasoningModeEffort mapping ===\n";
        $cm = (new ReflectionClass(ChatManager::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ChatManager::class, 'reasoningModeEffort');
        $m->setAccessible(true);

        $cases = [
            [null,      ['thinking', 'medium']],
            ['',        ['thinking', 'medium']],
            ['off',     ['instruct', null]],
            ['low',     ['thinking', 'low']],
            ['medium',  ['thinking', 'medium']],
            ['high',    ['thinking', 'high']],
            ['garbage', ['thinking', 'medium']],
        ];
        foreach ($cases as [$in, $expected]) {
            $actual = $m->invoke($cm, $in);
            $this->testEq('map(' . var_export($in, true) . ')', $expected, $actual);
        }
    }

    private function runRepository(): void
    {
        echo "\n=== AppSettingsRepository round-trip ===\n";
        $db = new class {
            /** @var array<string,string> */
            public array $stored = [];

            public function query(string $sql, array $params = []): array
            {
                $key = (string)($params[0] ?? '');
                return isset($this->stored[$key]) ? [['setting_value' => $this->stored[$key]]] : [];
            }

            public function executeStatement(string $sql, array $params = []): mixed
            {
                if (count($params) >= 2) {
                    $this->stored[(string)$params[0]] = (string)$params[1];
                }
                return null;
            }
        };

        $repo = new AppSettingsRepository($db);
        $this->testEq('unknown key -> default', 'medium', $repo->get('reasoning_effort', 'medium'));

        $repo->set('reasoning_effort', 'high');
        $this->testEq('set then get -> high', 'high', $repo->get('reasoning_effort', 'medium'));

        $repo->set('reasoning_effort', 'off');
        $this->testEq('overwrite -> off', 'off', $repo->get('reasoning_effort', 'medium'));
    }
}
