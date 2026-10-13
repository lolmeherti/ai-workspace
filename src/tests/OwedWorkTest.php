<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Logger;
use App\Repositories\ChatSessionRepository;
use App\Services\OwedWork;
use App\Services\TurnAnswers;

/**
 * The terminal link, and the owed-work derivations built on it.
 *
 * "Is this turn answered" is a query over rows rather than an ordering guess, and the table's primary key is what makes
 * a second answer to the same user row impossible: `TurnAnswers::record()` returns false instead of writing, and the
 * caller rolls its own terminal row back with it — the path `ChatManager` takes when two runners answer one turn.
 *
 * The three derivations are checked against seeded rows: an unanswered turn, a batch of held rows closed by one answer,
 * a fetched row with no atoms yet, and a row the user stopped.
 */
final class OwedWorkTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private const SESSION = 999000777;
    private const OTHER_SESSION = 999000778;

    public function __construct(private Database $db)
    {
        Logger::setDatabase($db);
    }

    public function run(): bool
    {
        $this->wipe();
        $sessions = new ChatSessionRepository($this->db);
        $sessions->ensureExists(self::SESSION);
        $sessions->ensureExists(self::OTHER_SESSION);

        $owed = new OwedWork($this->db);
        $links = new TurnAnswers($this->db);

        // ── a turn is owed until a terminal row closes it ─────────────────────────────────────────────
        $userA = $this->row(['role' => 'user', 'message' => 'what does the policy say about the fine']);
        $this->test('an unanswered user row is owed', in_array($userA, $this->ids($owed->turns(self::SESSION)), true));

        $answerA = $this->row(['role' => 'assistant', 'message' => 'the policy says']);
        $this->test('a terminal row links to it', $links->record([$userA], $answerA));
        $this->test('the closed turn is no longer owed', !in_array($userA, $this->ids($owed->turns(self::SESSION)), true));
        $this->testEq('the terminal row is readable', $answerA, (int)($links->terminalOf($userA)['terminal_row_id'] ?? 0));
        $this->testEq('and carries its kind', TurnAnswers::KIND_ANSWER, (string)($links->terminalOf($userA)['kind'] ?? ''));
        $this->testEq('and names the row it closes', [$userA], $links->userRowIdsOf($answerA));

        // ── a second answer to the same row is refused, and takes its own row with it ──────────────────
        $conn = $this->db->getConnection();
        $conn->beginTransaction();
        $duplicate = $this->row(['role' => 'assistant', 'message' => 'a second answer to the same question']);
        $refused = !$links->record([$userA], $duplicate);
        $conn->rollBack();
        $this->test('a second answer to the same user row is refused', $refused);
        $this->test('and its assistant row is rolled back with it', !$this->exists($duplicate));

        // ── a batch: three held rows, one answer ──────────────────────────────────────────────────────
        $held = [
            $this->row(['role' => 'user', 'message' => 'first of the batch', 'message_type' => 'queued']),
            $this->row(['role' => 'user', 'message' => 'second of the batch', 'message_type' => 'queued']),
            $this->row(['role' => 'user', 'message' => 'third of the batch', 'message_type' => 'queued']),
        ];
        $this->testEq('held rows are owed as one batch', $held, $this->ids($owed->batches(self::SESSION)));
        $this->test('held rows are not owed as turns', $this->ids($owed->turns(self::SESSION)) === []);

        $batchAnswer = $this->row(['role' => 'assistant', 'message' => 'answering all three']);
        $this->test('one answer closes all three', $links->record($held, $batchAnswer));
        $this->testEq('and names all three', $held, $links->userRowIdsOf($batchAnswer));

        $this->flipToText($held);
        $this->test('an answered batch leaves nothing owed',
            $this->ids($owed->batches(self::SESSION)) === [] && $this->ids($owed->turns(self::SESSION)) === []);

        // ── notes: a fetched row without atoms yet ────────────────────────────────────────────────────
        $raw = $this->row(['role' => 'system', 'message' => 'fetched material', 'message_type' => 'data_fetching']);
        $withAtoms = $this->row([
            'role' => 'system', 'message' => 'fetched material, already noted',
            'message_type' => 'data_fetching', 'atomic_context' => json_encode([['claim' => 'x']]),
        ]);
        $this->testEq('a fetched row with no atoms owes notes', [$raw], $this->ids($owed->atomization(self::SESSION)));
        $this->test('a row that already has atoms is not owed',
            !in_array($withAtoms, $this->ids($owed->atomization(self::SESSION)), true));

        $this->db->update('chat_history', ['atomic_context' => '[]'], ['id' => $raw]);
        $this->test('the row stops being owed once it carries atoms', $this->ids($owed->atomization(self::SESSION)) === []);

        // ── a stopped row is owed by nobody ───────────────────────────────────────────────────────────
        $stopped = $this->row(['role' => 'user', 'message' => 'never sent', 'message_type' => 'queued']);
        $this->db->update('chat_history', ['message_type' => 'stopped'], ['id' => $stopped]);
        $this->test('a stopped row is not owed as a batch', !in_array($stopped, $this->ids($owed->batches(self::SESSION)), true));
        $this->test('nor as a turn', !in_array($stopped, $this->ids($owed->turns(self::SESSION)), true));

        // ── the stop's closing line is a terminal row too ──────────────────────────────────────────────
        $interrupted = $this->row(['role' => 'user', 'message' => 'a question the user stopped']);
        $closing = $this->row(['role' => 'assistant', 'message' => \App\ChatManager::STOP_NOTICE]);
        $this->test('a closing line closes the turn it stops',
            $links->record([$interrupted], $closing, TurnAnswers::KIND_STOPPED));
        $this->test('the interrupted turn is no longer owed', !in_array($interrupted, $this->ids($owed->turns(self::SESSION)), true));
        $this->testEq('and the link says it was stopped',
            TurnAnswers::KIND_STOPPED, (string)($links->terminalOf($interrupted)['kind'] ?? ''));

        // ── order and scope ───────────────────────────────────────────────────────────────────────────
        $first = $this->row(['role' => 'user', 'message' => 'the older question']);
        $second = $this->row(['role' => 'user', 'message' => 'the newer question']);
        $other = $this->row(['role' => 'user', 'message' => 'another conversation', 'session_id' => self::OTHER_SESSION]);

        $this->testEq('owed turns come back oldest-first', [$first, $second], $this->ids($owed->turns(self::SESSION)));
        $this->testEq('and a session scope returns only that session', [$other], $this->ids($owed->turns(self::OTHER_SESSION)));

        echo sprintf("  %d passed, %d failed\n", $this->passed, $this->failed);
        $this->wipe();

        return $this->failed === 0;
    }

    /** @param array<string,mixed> $overrides */
    private function row(array $overrides): int
    {
        $data = array_merge([
            'session_id' => self::SESSION,
            'role' => 'user',
            'message' => 'seed',
            'message_type' => 'text',
            'token_estimate' => 4,
        ], $overrides);
        $this->db->insert('chat_history', $data);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /** @param array<int,int> $rowIds */
    private function flipToText(array $rowIds): void
    {
        $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
        $this->db->query(
            "UPDATE chat_history SET message_type = 'text' WHERE id IN ({$placeholders})",
            $rowIds
        );
    }

    private function exists(int $rowId): bool
    {
        return $this->db->query("SELECT id FROM chat_history WHERE id = ?", [$rowId]) !== [];
    }

    /**
     * @param array<int,array{row_id:int,session_id:int}> $rows
     * @return array<int,int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn(array $row): int => (int)$row['row_id'], $rows);
    }

    private function wipe(): void
    {
        foreach ([self::SESSION, self::OTHER_SESSION] as $sessionId) {
            $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
        }
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
