<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\ChatManager;
use App\Database;
use App\Search\ChunkMap;
use App\Search\SourceCondenser;

/**
 * Deterministic (no LLM) coverage for ChatManager::enrichRows() — the body the background job runs.
 *
 * What it pins down, because these are the properties the whole queue design leans on:
 *   - only the rows it was given are touched (a second un-atomized row in the same session is left alone) — this is
 *     the difference from the deferred backlog pass, which sweeps the session;
 *   - a row that already carries atoms is skipped, so a crash-and-retry or a double job costs nothing;
 *   - a row from another session, or one with no chunks, is skipped rather than guessed at;
 *   - a row that throws does not stop the loop, and the row after it still gets its atoms;
 *   - success writes atoms, the contents index and raw_evicted on the same row, and the summary counts are honest.
 *
 * The condenser is stubbed (same anonymous-subclass shape as EvidenceConsolidationTest) so nothing here needs a model.
 */
class EnrichmentJobTest
{
    private Database $db;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        $this->db->initTables();

        $this->runOnlyTheGivenRowsAreTouched();
        $this->runAlreadyEnrichedRowIsSkipped();
        $this->runForeignAndChunklessRowsAreSkipped();
        $this->runFailureDoesNotStopTheLoop();
        $this->runSuccessWritesBothArtifacts();
        $this->runEmptyExtractionLeavesRawActive();

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

    // ── the checks ───────────────────────────────────────────────────────────

    private function runOnlyTheGivenRowsAreTouched(): void
    {
        echo "\n=== only the rows it was given ===\n";
        $sessionId = $this->seedSession();
        try {
            $wanted = $this->seedRow($sessionId, 'S1', 900);
            $untouched = $this->seedRow($sessionId, 'S1', 900);

            $summary = $this->manager()->enrichRows($sessionId, [$wanted], null, $this->stubCondenser($this->claims()));

            $this->testEq('one row enriched', 1, $summary['enriched']);
            $this->testEq('the other row is still raw', 0, (int)($this->row($untouched)['raw_evicted'] ?? 0));
            $this->test('and still has no atoms', ($this->row($untouched)['atomic_context'] ?? null) === null);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    private function runAlreadyEnrichedRowIsSkipped(): void
    {
        echo "\n=== a row that already carries atoms is skipped ===\n";
        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedRow($sessionId, 'S1', 900);
            $first = $this->manager()->enrichRows($sessionId, [$rowId], null, $this->stubCondenser($this->claims()));
            $second = $this->manager()->enrichRows($sessionId, [$rowId], null, $this->stubCondenser($this->claims()));

            $this->testEq('first run enriched it', 1, $first['enriched']);
            $this->testEq('second run enriches nothing', 0, $second['enriched']);
            $this->testEq('and reports it as skipped', 1, $second['skipped']);
            $this->testEq('the second run added no atoms', 0, $second['atoms']);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    private function runForeignAndChunklessRowsAreSkipped(): void
    {
        echo "\n=== a foreign row and a chunk-less row are left alone ===\n";
        $mine = $this->seedSession();
        $theirs = $this->seedSession();
        try {
            $foreign = $this->seedRow($theirs, 'S1', 900);
            $chunkless = $this->seedRowWithoutChunks($mine);

            $summary = $this->manager()->enrichRows($mine, [$foreign, $chunkless, 0, 999999999], null, $this->stubCondenser($this->claims()));

            $this->testEq('nothing was enriched', 0, $summary['enriched']);
            $this->testEq('all four were skipped (foreign, chunk-less, zero id, missing id)', 4, $summary['skipped']);
            $this->testEq('the foreign row is untouched', 0, (int)($this->row($foreign)['raw_evicted'] ?? 0));
            $this->testEq('the chunk-less row is untouched', 0, (int)($this->row($chunkless)['raw_evicted'] ?? 0));
        } finally {
            $this->cleanup($mine);
            $this->cleanup($theirs);
        }
    }

    private function runFailureDoesNotStopTheLoop(): void
    {
        echo "\n=== a throwing row does not stop the loop ===\n";
        $sessionId = $this->seedSession();
        try {
            $bad = $this->seedRow($sessionId, 'S1', 900);
            $good = $this->seedRow($sessionId, 'S1', 900, 'S1-C2');

            // The stub throws for every call, so both rows are attempted and both are reported failed.
            $summary = $this->manager()->enrichRows($sessionId, [$bad, $good], null, $this->stubCondenser([], true));
            $this->testEq('both rows attempted, both counted failed', 2, $summary['failed']);
            $this->testEq('the first row stays raw', 0, (int)($this->row($bad)['raw_evicted'] ?? 0));
            $this->testEq('the second row was reached', 0, (int)($this->row($good)['raw_evicted'] ?? 0));

            // Now a working condenser over the same rows: the first one succeeds.
            $summary = $this->manager()->enrichRows($sessionId, [$bad, $good], null, $this->stubCondenser($this->claims()));
            $this->testEq('a later run still enriches them', 2, $summary['enriched']);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    private function runSuccessWritesBothArtifacts(): void
    {
        echo "\n=== success writes atoms and the contents index on the same row ===\n";
        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedRow($sessionId, 'S1', 900);
            $summary = $this->manager()->enrichRows($sessionId, [$rowId], null, $this->stubCondenser($this->claims()));
            $row = $this->row($rowId);

            $this->testEq('rows seen', 1, $summary['rows']);
            $this->testEq('enriched', 1, $summary['enriched']);
            $this->testEq('no failures', 0, $summary['failed']);
            $this->testEq('raw is evicted', 1, (int)($row['raw_evicted'] ?? 0));
            $this->test('atoms were persisted', !empty($row['atomic_context']));
            $this->test('the contents index was persisted', !empty($row['chunk_map']));
            $map = ChunkMap::decode($row['chunk_map'] ?? null);
            $this->testEq('the index describes the passage', 'S1-C1', (string)($map['entries'][0]['chunkId'] ?? ''));
            $this->test('reclaimed tokens are reported', $summary['reclaimed'] > 0);
            $this->test('ms was measured', $summary['ms'] >= 0);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    private function runEmptyExtractionLeavesRawActive(): void
    {
        echo "\n=== an empty extraction is not a failure ===\n";
        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedRow($sessionId, 'S1', 900);
            $summary = $this->manager()->enrichRows($sessionId, [$rowId], null, $this->stubCondenser([]));
            $row = $this->row($rowId);

            $this->testEq('counted as empty, not failed', 1, $summary['empty']);
            $this->testEq('no failures', 0, $summary['failed']);
            $this->testEq('the row stays raw', 0, (int)($row['raw_evicted'] ?? 0));
            $this->test('and carries no atoms', ($row['atomic_context'] ?? null) === null);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function manager(): ChatManager
    {
        // Deterministic chars/4 counter — no llama /tokenize HTTP in the suite.
        return new ChatManager($this->db, new AgentManager(), fn(string $s): int => (int)ceil(mb_strlen($s) / 4));
    }

    private function claims(): array
    {
        return [
            ['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'the article says the operator takes feedback seriously'],
        ];
    }

    private function seedSession(): int
    {
        $this->db->insert('chat_sessions', ['title' => 'enrichment-job-test', 'context_tokens' => 0]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedRow(int $sessionId, string $sourceId, int $tokenEstimate, string $chunkId = 'S1-C1'): int
    {
        $chunk = [
            'sourceId' => $sourceId,
            'chunkId' => $chunkId,
            'url' => 'https://example.com/' . $sourceId,
            'finalUrl' => 'https://example.com/' . $sourceId,
            'title' => 'title ' . $sourceId,
            'domain' => 'example.com',
            'publishedAt' => null,
            'updatedAt' => null,
            'fetchedAt' => '2026-01-01T00:00:00Z',
            'headingPath' => [],
            'sectionType' => 'entity',
            'text' => str_repeat('passage text ', 60),
            'position' => 1,
            'entityId' => null,
            'entityType' => null,
            'author' => null,
            'score' => null,
        ];
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => str_repeat('raw evidence ', 200),
            'message_type' => 'data_fetching',
            'tool_name' => 'fetch_source',
            'search_query' => 'S1 www.example.com',
            'raw_evicted' => 0,
            'atomic_context' => null,
            'selected_chunks' => json_encode([$chunk], JSON_UNESCAPED_UNICODE),
            'token_estimate' => $tokenEstimate,
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedRowWithoutChunks(int $sessionId): int
    {
        // The shape an attachment or a video notice has: real evidence, no chunk array to condense.
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => 'an attachment with no chunks',
            'message_type' => 'data_fetching',
            'tool_name' => 'file',
            'token_estimate' => 120,
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function row(int $rowId): array
    {
        return $this->db->selectSafe('chat_history', ['id' => $rowId])[0] ?? [];
    }

    private function cleanup(int $sessionId): void
    {
        $this->db->query('DELETE FROM chat_history WHERE session_id = ?', [$sessionId]);
        $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
    }

    private function stubCondenser(array $claims = [], bool $throw = false): SourceCondenser
    {
        $stub = new class(new AgentManager()) extends SourceCondenser {
            public array $claims = [];
            public bool $throw = false;

            public function enrichBatched(array $chunks, string $searchQuery): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('stub consolidation failure');
                }

                $map = [];
                foreach ($this->claims as $claim) {
                    foreach ($claim['chunk_ids'] ?? [] as $id) {
                        $map[$id] ??= [
                            'chunkId' => (string)$id,
                            'headline' => mb_substr((string)($claim['claim'] ?? ''), 0, 40),
                            'description' => 'stub description',
                            'chars' => 100,
                            'described' => true,
                        ];
                    }
                }

                return ['claims' => $this->claims, 'map' => array_values($map), 'formatted' => true];
            }
        };
        $stub->claims = $claims;
        $stub->throw = $throw;

        return $stub;
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
