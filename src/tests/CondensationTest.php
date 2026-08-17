<?php

declare(strict_types=1);

namespace App\Tests;

use App\Agents\ContextCondenser;

class CondensationTest
{
    private \App\Database $db;
    private ContextCondenser $condenser;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->db = $db;
        $this->condenser = new ContextCondenser(new \App\AgentManager());
    }

    public function run(): bool
    {
        $this->db->initTables();
        $this->runCommitCondensationPreservesEvidence();

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

    // ===================================================================
    // commitCondensation — evidence survives; conversation condenses
    // ===================================================================
    private function runCommitCondensationPreservesEvidence(): void
    {
        echo "\n=== ContextCondenser::commitCondensation ===\n";

        $sessionId = $this->seedSession();
        try {
            $this->seedHistory($sessionId, 'user', 'Q1_SENTINEL', 'text', 1);
            $this->seedHistory($sessionId, 'system', 'EVIDENCE1_SENTINEL', 'data_fetching', 1);
            $this->seedHistory($sessionId, 'assistant', 'A1_SENTINEL', 'text', 1);
            $this->seedHistory($sessionId, 'user', 'Q2_SENTINEL', 'text', 1);
            $this->seedHistory($sessionId, 'system', 'EVIDENCE2_SENTINEL', 'data_fetching', 0);
            $this->seedHistory($sessionId, 'assistant', 'A2_SENTINEL', 'text', 1);

            $result = $this->condenser->commitCondensation($this->db, $sessionId, 'THE_SUMMARY', [], true);
            $this->test('commit returns success', ($result['status'] ?? '') === 'success');

            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $msgs = array_map(fn($r) => $r['message'], $history);

            $evidence = array_values(array_filter($history, fn($r) => ($r['message_type'] ?? '') === 'data_fetching'));
            $this->testEq('data_fetching rows survive', 2, count($evidence));
            $this->test('active evidence preserved', in_array('EVIDENCE1_SENTINEL', $msgs, true));
            $this->test('evicted evidence preserved', in_array('EVIDENCE2_SENTINEL', $msgs, true));

            $evicted = array_values(array_filter($evidence, fn($r) => $r['message'] === 'EVIDENCE2_SENTINEL'));
            $this->testEq('evicted row stays evicted (not resurrected)', 0, (int)($evicted[0]['active_context'] ?? -1));

            $summary = array_values(array_filter($history, fn($r) => ($r['message_type'] ?? '') === 'condensation_summary'));
            $this->testEq('one condensation summary row', 1, count($summary));
            $this->test('summary text present', str_contains($summary[0]['message'] ?? '', 'THE_SUMMARY'));

            $this->test('old conversation condensed away', !in_array('Q1_SENTINEL', $msgs, true) && !in_array('A1_SENTINEL', $msgs, true));
            $this->test('recent conversation kept', in_array('Q2_SENTINEL', $msgs, true) && in_array('A2_SENTINEL', $msgs, true));
        } finally {
            $this->db->query("DELETE FROM chat_sessions WHERE id = ?", [$sessionId]);
        }
    }

    private function seedSession(): int
    {
        $this->db->insert('chat_sessions', ['title' => 'condense-test', 'context_tokens' => 0]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedHistory(int $sessionId, string $role, string $message, string $messageType, int $activeContext): void
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => $role,
            'message' => $message,
            'message_type' => $messageType,
            'active_context' => $activeContext,
        ]);
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
