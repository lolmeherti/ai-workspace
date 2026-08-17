<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\SourceSequence;

class SourceSequenceTest
{
    private \App\Database $db;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        $this->db->initTables();
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $this->runMaxSeqFromMap();
            $this->runNextSourceSeq();
            $this->runSplitQueryCollision();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

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

    private function runMaxSeqFromMap(): void
    {
        echo "\n=== SourceSequence::maxSeqFromMap ===\n";

        $this->testEq('empty map -> 0', 0, SourceSequence::maxSeqFromMap([]));
        $this->testEq('S1,S3,S2 -> 3', 3, SourceSequence::maxSeqFromMap(['S1' => 1, 'S3' => 1, 'S2' => 1]));
        $this->testEq('ignores non-S keys', 2, SourceSequence::maxSeqFromMap(['foo' => 1, 'S2' => 1]));
        $this->testEq('S10 -> 10', 10, SourceSequence::maxSeqFromMap(['S10' => 1]));
    }

    private function runNextSourceSeq(): void
    {
        echo "\n=== SourceSequence::nextSourceSeq ===\n";

        $s1 = $this->seedSession();
        $this->testEq('empty session -> 1', 1, SourceSequence::nextSourceSeq($this->db, $s1));

        $s2 = $this->seedSession();
        $this->seedHistory($s2, 'system', 'data_fetching', null);
        $this->testEq('source-less data_fetching -> 1', 1, SourceSequence::nextSourceSeq($this->db, $s2));

        $s3 = $this->seedSession();
        $this->seedHistory($s3, 'system', 'data_fetching', json_encode([
            'S1' => ['url' => 'a'], 'S2' => ['url' => 'b'], 'S5' => ['url' => 'c'],
        ]));
        $this->testEq('data_fetching S1,S2,S5 -> 6 (restart survives)', 6, SourceSequence::nextSourceSeq($this->db, $s3));
    }

    private function runSplitQueryCollision(): void
    {
        echo "\n=== split-query accumulation (no collision) ===\n";

        $combined = [];
        $startSeq = 4;

        $a = ['S4' => ['url' => 'a1'], 'S5' => ['url' => 'a2']];
        $combined = array_merge($combined, $a);
        $startSeq = SourceSequence::maxSeqFromMap($combined) + 1;
        $this->testEq('after subquery A: 2 entries', 2, count($combined));
        $this->testEq('after subquery A: next start 6', 6, $startSeq);

        $b = ['S6' => ['url' => 'b1'], 'S7' => ['url' => 'b2'], 'S8' => ['url' => 'b3']];
        $combined = array_merge($combined, $b);
        $startSeq = SourceSequence::maxSeqFromMap($combined) + 1;

        $this->testEq('combined: 5 entries survive', 5, count($combined));
        $this->testEq('combined keys exactly S4..S8', ['S4', 'S5', 'S6', 'S7', 'S8'], array_keys($combined));
        $this->test('combined: no duplicate keys', count(array_keys($combined)) === count(array_unique(array_keys($combined))));
        $this->testEq('next sequence is 9', 9, $startSeq);
    }

    private function seedSession(): int
    {
        $this->db->insert('chat_sessions', ['title' => 'src-seq-test', 'context_tokens' => 0]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedHistory(int $sessionId, string $role, string $messageType, ?string $sourceMap = null): void
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => $role,
            'message' => 'x',
            'message_type' => $messageType,
            'source_map' => $sourceMap,
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
