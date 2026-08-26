<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\ChatManager;
use App\Search\SourceCondenser;
use App\Services\PromptAssemblyService;

/**
 * Deterministic (no LLM) coverage for the evidence atomization lifecycle: the
 * web evidence answer uses rich selected evidence; atomic_context starts NULL;
 * single-row atomization (`atomizeRow`) writes atoms to the SAME data_fetching
 * row (no new row); failure/empty keeps raw evidence active; the next turn
 * prefers atoms automatically; and the deferred backlog pass reclaims only
 * enough rows (partial, largest-first), never the whole backlog at once.
 */
class EvidenceConsolidationTest
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
        $this->runSuccessPersistsAtomsToCorrectRow();
        $this->runSuccessSwitchesInjectionRawToAtoms();
        $this->runFailureLeavesRawInjected();
        $this->runEmptyLeavesRawInjected();
        $this->runPartialReclamationLargestFirst160k();
        $this->runPartialReclamationReclaimsOnlyEnough();
        $this->runContextProfilesChangeTrigger();
        $this->runReclaimedIsRawMinusAtom();
        $this->runInjectionRuleMatrix();

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
    // 1. success -> atoms on the correct row, no new evidence row
    // ===================================================================
    private function runSuccessPersistsAtomsToCorrectRow(): void
    {
        echo "\n=== consolidation success: correct row, no new row ===\n";

        $sessionId = $this->seedSession();
        try {
            $rowA = $this->seedDataFetching($sessionId, '<source id="S1">raw A</source>');
            $rowB = $this->seedDataFetching($sessionId, '<source id="S2">raw B</source>');
            $before = count($this->db->selectSafe('chat_history', ['session_id' => $sessionId]));

            $claims = [
                ['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'Knicks won the 2026 NBA Finals'],
            ];

            $events = [];
            $this->invokeConsolidate(
                $this->makeChatManager(),
                [$rowA => ['chunks' => [], 'query' => 'who won the nba']],
                $this->stubCondenser($claims),
                $events
            );

            $rows = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int)$r['id']] = $r;
            }

            $decoded = json_decode($byId[$rowA]['atomic_context'] ?? '', true);
            $this->test('atomic_context written on target row',
                is_array($decoded) && count($decoded) === 1 && ($decoded[0]['claim'] ?? '') === 'Knicks won the 2026 NBA Finals');
            $this->test('other data_fetching row untouched', ($byId[$rowB]['atomic_context'] ?? null) === null);
            $this->test('atomic_tokens written on target row', (int)($byId[$rowA]['atomic_tokens'] ?? 0) > 0);
            $this->testEq('raw_evicted set on target row (summary state)', 1, (int)($byId[$rowA]['raw_evicted'] ?? 0));
            $this->testEq('other row raw_evicted untouched', 0, (int)($byId[$rowB]['raw_evicted'] ?? 0));
            $this->testEq('no new chat_history row created', $before, count($rows));
            $this->test('emits consolidation_start then consolidation_done',
                ($events[0]['event'] ?? '') === 'consolidation_start'
                && ($events[count($events) - 1]['event'] ?? '') === 'consolidation_done');
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // 2. success -> next turn injects atoms, not raw
    // ===================================================================
    private function runSuccessSwitchesInjectionRawToAtoms(): void
    {
        echo "\n=== next-turn injection switches raw -> atoms ===\n";

        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedDataFetching($sessionId, 'full raw evidence text');
            $claims = [['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'Knicks won 4-1']];

            $events = [];
            $this->invokeConsolidate(
                $this->makeChatManager(),
                [$rowId => ['chunks' => [], 'query' => 'q']],
                $this->stubCondenser($claims),
                $events
            );

            $row = $this->db->selectSafe('chat_history', ['id' => $rowId])[0];
            $svc = new PromptAssemblyService($this->db, '/tmp');

            $nextTurn = $this->injectedContent($svc, $row, []);
            $this->test('next turn injects atoms not raw message',
                str_contains($nextTurn, '[S1] Knicks won 4-1') && !str_contains($nextTurn, 'full raw evidence text'));
            $this->test('immediate answer (rich row) still uses raw message',
                $this->injectedContent($svc, $row, [$rowId]) === 'full raw evidence text');
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // 3. failure -> raw evidence stays active
    // ===================================================================
    private function runFailureLeavesRawInjected(): void
    {
        echo "\n=== consolidation failure keeps raw evidence active ===\n";

        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedDataFetching($sessionId, 'full raw evidence text');

            $events = [];
            $this->invokeConsolidate(
                $this->makeChatManager(),
                [$rowId => ['chunks' => [], 'query' => 'q']],
                $this->stubCondenser([], true),
                $events
            );

            $row = $this->db->selectSafe('chat_history', ['id' => $rowId])[0];
            $this->test('atomic_context stays null on failure', ($row['atomic_context'] ?? null) === null);
            $this->test('atomic_tokens stays null on failure', ($row['atomic_tokens'] ?? null) === null);
            $this->testEq('raw_evicted stays 0 on failure', 0, (int)($row['raw_evicted'] ?? 0));

            $svc = new PromptAssemblyService($this->db, '/tmp');
            $this->test('raw evidence still injected', $this->injectedContent($svc, $row, []) === 'full raw evidence text');
            $this->test('emits consolidation_start then consolidation_error',
                ($events[0]['event'] ?? '') === 'consolidation_start'
                && ($events[count($events) - 1]['event'] ?? '') === 'consolidation_error');
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // 4. empty atoms (no durable facts) -> raw evidence stays active
    // ===================================================================
    private function runEmptyLeavesRawInjected(): void
    {
        echo "\n=== empty atoms keep raw evidence active ===\n";

        $sessionId = $this->seedSession();
        try {
            $rowId = $this->seedDataFetching($sessionId, 'full raw evidence text');

            $events = [];
            $this->invokeConsolidate(
                $this->makeChatManager(),
                [$rowId => ['chunks' => [], 'query' => 'q']],
                $this->stubCondenser([]),
                $events
            );

            $row = $this->db->selectSafe('chat_history', ['id' => $rowId])[0];
            $this->test('atomic_context stays null on empty atoms', ($row['atomic_context'] ?? null) === null);
            $this->test('atomic_tokens stays null on empty atoms', ($row['atomic_tokens'] ?? null) === null);
            $this->testEq('raw_evicted stays 0 on empty atoms', 0, (int)($row['raw_evicted'] ?? 0));

            $svc = new PromptAssemblyService($this->db, '/tmp');
            $this->test('raw evidence still injected', $this->injectedContent($svc, $row, []) === 'full raw evidence text');
            $this->test('empty atoms is not an error (still consolidation_done)',
                ($events[count($events) - 1]['event'] ?? '') === 'consolidation_done');
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // 5. deferred backlog -> PARTIAL reclamation, context-scaled threshold
    // ===================================================================
    private function runPartialReclamationLargestFirst160k(): void
    {
        echo "\n=== 160k: largest-first, one row when one suffices ===\n";

        $sessionId = $this->seedSession();
        try {
            // 13k + 12k + 11k = 36k backlog -> crosses the 30k (160k ctx) threshold.
            // Atomize the 13k row; remaining ~23k is below target -> stop.
            $this->seedUnatomizedRow($sessionId, 'SA', 12000);
            $this->seedUnatomizedRow($sessionId, 'SB', 11000);
            $this->seedUnatomizedRow($sessionId, 'SC', 13000);

            $claims = [['source_id' => 'SC', 'chunk_ids' => ['SC-C1'], 'claim' => 'won 4-1']];

            $events = [];
            $this->invokeBacklogAtomization($sessionId, 160000, $this->stubCondenser($claims), $events);

            $this->testEq('exactly one row atomized', 1, count($this->atomizedTokenEstimates($sessionId)));
            $this->testEq('the atomized row is the largest (13k)', [13000], $this->atomizedTokenEstimates($sessionId));
            $this->testEq('consolidation_done reports persisted=1',
                1, $this->eventData($events, 'consolidation_done')['persisted'] ?? null);
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    private function runPartialReclamationReclaimsOnlyEnough(): void
    {
        echo "\n=== 160k: reclaims only enough rows (not everything) ===\n";

        $sessionId = $this->seedSession();
        try {
            // 3 x 20k = 60k backlog -> crosses the 30k (160k) threshold.
            // Largest-first: 60k -> 40k -> 20k, stop once below 30k.
            // Two rows reclaimed, one left raw.
            for ($i = 1; $i <= 3; $i++) {
                $this->seedUnatomizedRow($sessionId, "S{$i}", 20000);
            }

            $claims = [['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'Knicks won 4-1']];

            $events = [];
            $this->invokeBacklogAtomization($sessionId, 160000, $this->stubCondenser($claims), $events);

            $this->testEq('2 of 3 rows atomized (backlog back below target)',
                2, count($this->atomizedTokenEstimates($sessionId)));
            $this->testEq('consolidation_done reports persisted=2',
                2, $this->eventData($events, 'consolidation_done')['persisted'] ?? null);
            $this->test('consolidation events emitted',
                in_array('consolidation_start', array_column($events, 'event'), true)
                && in_array('consolidation_done', array_column($events, 'event'), true));
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    private function runContextProfilesChangeTrigger(): void
    {
        echo "\n=== same backlog, different context profiles ===\n";

        $claims = [['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'fact']];

        // 8k ctx (threshold 1.5k): 6k backlog triggers -> both rows reclaimed.
        $s8 = $this->seedSession();
        try {
            $this->seedUnatomizedRow($s8, 'S1', 3000);
            $this->seedUnatomizedRow($s8, 'S2', 3000);
            $events = [];
            $this->invokeBacklogAtomization($s8, 8000, $this->stubCondenser($claims), $events);
            $this->testEq('8k: 6k backlog triggers (2 rows atomized)', 2, count($this->atomizedTokenEstimates($s8)));
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$s8]);
        }

        // 25k ctx (threshold 8k): 6k backlog is below target -> nothing atomized.
        $s25 = $this->seedSession();
        try {
            $this->seedUnatomizedRow($s25, 'S1', 3000);
            $this->seedUnatomizedRow($s25, 'S2', 3000);
            $events = [];
            $this->invokeBacklogAtomization($s25, 25000, $this->stubCondenser($claims), $events);
            $this->testEq('25k: 6k backlog defers (0 rows atomized)', 0, count($this->atomizedTokenEstimates($s25)));
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$s25]);
        }

        // 160k ctx (threshold 30k): 6k backlog is far below target -> nothing.
        $s160 = $this->seedSession();
        try {
            $this->seedUnatomizedRow($s160, 'S1', 3000);
            $this->seedUnatomizedRow($s160, 'S2', 3000);
            $events = [];
            $this->invokeBacklogAtomization($s160, 160000, $this->stubCondenser($claims), $events);
            $this->testEq('160k: 6k backlog defers (0 rows atomized)', 0, count($this->atomizedTokenEstimates($s160)));
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$s160]);
        }
    }

    private function runReclaimedIsRawMinusAtom(): void
    {
        echo "\n=== reclaimed = raw - atom (not full raw) ===\n";

        $sessionId = $this->seedSession();
        try {
            // 8k ctx (threshold 1.5k). Row A raw 2000, Row B raw 1000.
            // Stub atoms are ~600 tokens, so atomizing A reclaims 1400 (not 2000),
            // leaving 1600 >= 1500 -> the loop must continue to B. Under the old
            // "reclaim full raw" accounting A alone (1000 < 1500) would have stopped.
            $this->seedUnatomizedRow($sessionId, 'SA', 2000);
            $this->seedUnatomizedRow($sessionId, 'SB', 1000);

            // "[S1] " (5 chars) + 2394 x's + newline = 2400 chars -> 600 tokens.
            $claims = [['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => str_repeat('x', 2394)]];

            $events = [];
            $this->invokeBacklogAtomization($sessionId, 8000, $this->stubCondenser($claims), $events);

            $this->testEq('both rows atomized (raw-atom accounting)', 2, count($this->atomizedTokenEstimates($sessionId)));
            $this->testEq('consolidation_done reports persisted=2',
                2, $this->eventData($events, 'consolidation_done')['persisted'] ?? null);
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // 9. injection rule matrix — raw_evicted gates raw; atoms always inject
    // ===================================================================
    private function runInjectionRuleMatrix(): void
    {
        echo "\n=== injection rule: raw_evicted + atoms presence ===\n";

        $sessionId = $this->seedSession();
        try {
            $atoms = json_encode([['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'atom fact']]);
            $svc = new PromptAssemblyService($this->db, '/tmp');

            $c1 = $this->injectedContent($svc, $this->fetchRow($this->seedRowWithState($sessionId, 'RAW_TEXT', 0, $atoms)), []);
            $this->test('raw live + atoms -> raw AND atoms injected',
                str_contains($c1, 'RAW_TEXT') && str_contains($c1, '[S1] atom fact'));

            $c2 = $this->injectedContent($svc, $this->fetchRow($this->seedRowWithState($sessionId, 'RAW_ONLY', 0, null)), []);
            $this->test('raw live, no atoms -> raw only', $c2 === 'RAW_ONLY');

            $c3 = $this->injectedContent($svc, $this->fetchRow($this->seedRowWithState($sessionId, 'EVICTED_RAW', 1, $atoms)), []);
            $this->test('raw evicted + atoms -> atoms only',
                !str_contains($c3, 'EVICTED_RAW') && str_contains($c3, '[S1] atom fact'));

            $c4 = $this->injectedContent($svc, $this->fetchRow($this->seedRowWithState($sessionId, 'OFF_RAW', 1, null)), []);
            $this->test('raw evicted, no atoms -> empty (off)', $c4 === '');
        } finally {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
    }

    // ===================================================================
    // helpers
    // ===================================================================
    private function seedRowWithState(int $sessionId, string $message, int $rawEvicted, ?string $atomic): int
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => $message,
            'message_type' => 'data_fetching',
            'raw_evicted' => $rawEvicted,
            'atomic_context' => $atomic,
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function fetchRow(int $id): array
    {
        return $this->db->selectSafe('chat_history', ['id' => $id])[0];
    }

    private function makeChatManager(): ChatManager
    {
        // Deterministic chars/4 stub — no llama /tokenize HTTP in the test suite.
        return new ChatManager($this->db, new AgentManager(), fn(string $s): int => (int)ceil(mb_strlen($s) / 4));
    }

    private function stubCondenser(array $claims = [], bool $throw = false): SourceCondenser
    {
        $stub = new class(new AgentManager()) extends SourceCondenser {
            public array $claims = [];
            public bool $throw = false;

            public function condenseBatched(array $chunks, string $searchQuery): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('stub consolidation failure');
                }
                return $this->claims;
            }
        };
        $stub->claims = $claims;
        $stub->throw = $throw;
        return $stub;
    }

    private function invokeConsolidate(ChatManager $cm, array $pending, SourceCondenser $condenser, array &$events): void
    {
        $m = new \ReflectionMethod(ChatManager::class, 'consolidateFreshEvidence');
        $m->setAccessible(true);
        $m->invoke($cm, $pending, function (string $event, array $data) use (&$events): void {
            $events[] = ['event' => $event, 'data' => $data];
        }, $condenser);
    }

    private function invokeBacklogAtomization(int $sessionId, int $ctxSize, SourceCondenser $condenser, array &$events): void
    {
        $m = new \ReflectionMethod(ChatManager::class, 'atomizeBacklogIfNeeded');
        $m->setAccessible(true);
        $m->invoke($this->makeChatManager(), $sessionId, function (string $event, array $data) use (&$events): void {
            $events[] = ['event' => $event, 'data' => $data];
        }, $condenser, $ctxSize);
    }

    /** token_estimate values of data_fetching rows that now carry atomic_context, sorted. */
    private function atomizedTokenEstimates(int $sessionId): array
    {
        $tokens = [];
        foreach ($this->db->selectSafe('chat_history', ['session_id' => $sessionId]) as $r) {
            if (($r['message_type'] ?? '') === 'data_fetching' && ($r['atomic_context'] ?? null) !== null) {
                $tokens[] = (int)$r['token_estimate'];
            }
        }
        sort($tokens);
        return $tokens;
    }

    /** Find the first emitted event payload by name (events are ['event'=>, 'data'=>]). */
    private function eventData(array $events, string $name): ?array
    {
        foreach ($events as $e) {
            if (($e['event'] ?? '') === $name) {
                return $e['data'] ?? [];
            }
        }
        return null;
    }

    private function injectedContent(PromptAssemblyService $svc, array $row, array $richRowIds = []): string
    {
        $m = new \ReflectionMethod(PromptAssemblyService::class, 'injectedEvidenceContent');
        $m->setAccessible(true);
        return (string) $m->invoke($svc, $row, $richRowIds);
    }

    private function seedSession(): int
    {
        $this->db->insert('chat_sessions', ['title' => 'consolidation-test', 'context_tokens' => 0]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedDataFetching(int $sessionId, string $message, ?string $atomic = null): int
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => $message,
            'message_type' => 'data_fetching',
            'atomic_context' => $atomic,
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedUnatomizedRow(int $sessionId, string $sourceId, int $tokenEstimate): int
    {
        $chunk = [
            'sourceId' => $sourceId,
            'chunkId' => "{$sourceId}-C1",
            'url' => 'https://example.com/' . $sourceId,
            'finalUrl' => 'https://example.com/' . $sourceId,
            'title' => 'title ' . $sourceId,
            'domain' => 'example.com',
            'publishedAt' => null,
            'updatedAt' => null,
            'fetchedAt' => '2026-01-01T00:00:00Z',
            'headingPath' => [],
            'sectionType' => 'entity',
            'text' => str_repeat('evidence ', 100),
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
            'raw_evicted' => 0,
            'atomic_context' => null,
            'selected_chunks' => json_encode([$chunk]),
            'search_query' => 'who won',
            'token_estimate' => $tokenEstimate,
        ]);
        return (int)$this->db->getConnection()->lastInsertId();
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
