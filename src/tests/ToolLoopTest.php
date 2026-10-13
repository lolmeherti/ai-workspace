<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\ChatManager;
use App\Database;
use App\Logger;
use App\Repositories\ChatSessionRepository;

/**
 * The tool loop's mechanics, deterministically — no model involved.
 *
 * The live workflow test shows what a model DOES; this one shows what the turn DOES with whatever the
 * model says, which is the part that has to be true every time:
 *
 *   - a round with no tool calls ends the turn and the answer is the pass's own content;
 *   - a second round happens when the model asks again, and the prompt grows append-only, so the
 *     prefix the engine cached is still valid (asserted by hashing every message the first pass saw
 *     and requiring them byte-identical in later passes);
 *   - the round cap stops a model that keeps asking, forces the answer from what was gathered, and
 *     says so in the event log;
 *   - a browsing call with no arguments survives the dispatch guard (the regression that would have
 *     made the "what do the stored sources contain" call impossible);
 *   - the reminder lands on the turn's last evidence row, not in the turn's own message array.
 */
class ToolLoopTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(private Database $db)
    {
        Logger::setDatabase($db);
        Logger::clearModelName();
    }

    public function run(): bool
    {
        echo "\n=== tool loop mechanics (scripted agent, no model) ===\n";

        $this->testEndsAfterOneRound();
        $this->testSecondRoundAndAppendOnlyPrompt();
        $this->testRoundCapForcesTheAnswer();
        $this->testBareBrowseCallSurvivesTheGuard();

        echo "\n--- ToolLoop: {$this->passed} passed, {$this->failed} failed ---\n";
        foreach ($this->failures as $f) {
            echo "  FAIL: {$f}\n";
        }

        return $this->failed === 0;
    }

    // ── cases ────────────────────────────────────────────────────────────

    private function testEndsAfterOneRound(): void
    {
        $session = 999000501;
        $this->seedSession($session);

        $agent = $this->scripted([
            ['tool_calls' => [$this->call('search_local', ['queries' => ['anything']])]],
            ['content' => 'Answered after one round.'],
        ]);

        $capBefore = $this->events('tool_round_cap');
        $result = $this->turn($session, $agent);

        $this->test('one tool round then an answer takes two passes', $agent->passes === 2);
        $this->test('and the answer is the pass\'s own content',
            str_contains((string)($result['message'] ?? ''), 'Answered after one round.'));
        $this->test('no forced answer was generated', $agent->plainChats === 0);
        $this->test('the cap was not reported', $this->events('tool_round_cap') === $capBefore);
        $this->test('the turn records that it used tools', $this->usedTools($session));
        $this->test('the round wrote its evidence row', $this->evidenceRows($session) === 1);
        $this->testContinuity($agent, 'round then answer');

        $this->test('the reminder is stored on that row, not in the turn array',
            $this->reminderRow($session) > 0);

        $this->cleanup($session);
    }

    private function testSecondRoundAndAppendOnlyPrompt(): void
    {
        $session = 999000502;
        $this->seedSession($session);

        // Round 1 fetches (a durable row), round 2 browses stored sources (transient), round 3 answers.
        $agent = $this->scripted([
            ['tool_calls' => [$this->call('search_local', ['queries' => ['first']])]],
            ['tool_calls' => [$this->call('search_session_evidence', [])]],
            ['content' => 'Answered after two rounds.'],
        ]);

        $result = $this->turn($session, $agent);

        $this->test('two tool rounds then an answer takes three passes', $agent->passes === 3);
        $this->test('and still answers from the last pass',
            str_contains((string)($result['message'] ?? ''), 'Answered after two rounds.'));
        $this->test('the browsing call added no durable row of its own', $this->evidenceRows($session) === 1);
        $this->testContinuity($agent, 'two rounds');

        $this->cleanup($session);
    }

    private function testRoundCapForcesTheAnswer(): void
    {
        $session = 999000503;
        $this->seedSession($session);

        // Every pass keeps asking. The turn must stop at the cap and answer anyway.
        $agent = $this->scripted([
            ['tool_calls' => [$this->call('search_local', ['queries' => ['again']])]],
            ['tool_calls' => [$this->call('search_local', ['queries' => ['again']])]],
            ['tool_calls' => [$this->call('search_local', ['queries' => ['again']])]],
            ['tool_calls' => [$this->call('search_local', ['queries' => ['again']])]],
            ['content' => 'Forced answer from what was gathered.'],
        ]);
        $agent->plainAnswer = 'Forced answer from what was gathered.';

        $capBefore = $this->events('tool_round_cap');
        $result = $this->turn($session, $agent);

        // Three scripted rounds, then the forced answer — which is a plain chat() call, not another
        // tool-capable pass.
        $this->test('the model gets three tool rounds, not more', $agent->passes === 3);
        $this->test('the cap is reported in the event log', $this->events('tool_round_cap') === $capBefore + 1);
        $this->test('and the answer is generated anyway', $agent->plainChats === 1);
        $this->test('carrying the forced pass\'s content',
            str_contains((string)($result['message'] ?? ''), 'Forced answer from what was gathered.'));
        $this->test('with the last round\'s evidence present',
            $this->evidenceRows($session) === 3);
        $this->testContinuity($agent, 'cap');

        $this->cleanup($session);
    }

    private function testBareBrowseCallSurvivesTheGuard(): void
    {
        $session = 999000504;
        $this->seedSession($session);

        // The call the tool's description invites: no arguments at all.
        $agent = $this->scripted([
            ['tool_calls' => [$this->call('search_session_evidence', [])]],
            ['content' => 'Listed the stored sources.'],
        ]);

        $droppedBefore = $this->droppedCalls();
        $executedBefore = $this->events('tool_executed');
        $this->turn($session, $agent);

        $this->test('a browsing call with no arguments is not dropped', $this->droppedCalls() === $droppedBefore);
        $this->test('it reached the tool (executed, not merely attempted)',
            $this->events('tool_executed') > $executedBefore);

        $this->cleanup($session);
    }

    // ── the append-only assertion ────────────────────────────────────────

    /**
     * Every message the first pass saw must still be there, unchanged, in every later pass. If a round
     * re-anchored or rewrote anything behind the tail, the engine would lose the prefix it had cached —
     * which is the whole reason rounds are append-only.
     */
    private function testContinuity(ScriptedAgent $agent, string $label): void
    {
        $first = $agent->messageHashes[0] ?? [];
        $stable = true;
        $moved = '';

        for ($pass = 1; $pass < count($agent->messageHashes); $pass++) {
            $seen = $agent->messageHashes[$pass];
            foreach ($first as $index => $hash) {
                if (!isset($seen[$index]) || $seen[$index] !== $hash) {
                    $stable = false;
                    $moved = "pass " . ($pass + 1) . " message {$index}";
                    break 2;
                }
            }
            // Later passes may only add.
            if (count($seen) < count($first)) {
                $stable = false;
                $moved = "pass " . ($pass + 1) . " shrank the prompt";
                break;
            }
        }

        $this->test("the prompt stays append-only across rounds ({$label})", $stable, $moved);
    }

    // ── harness ──────────────────────────────────────────────────────────

    private function seedSession(int $session): void
    {
        $this->db->query('DELETE FROM chat_history WHERE session_id = ?', [$session]);
        $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$session]);
        (new ChatSessionRepository($this->db))->ensureExists($session);
    }

    private function cleanup(int $session): void
    {
        $this->db->query('DELETE FROM chat_history WHERE session_id = ?', [$session]);
        $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$session]);
        $this->db->query("DELETE FROM app_events WHERE event_type IN ('tool_round_cap', 'tool_executed') AND created_at > (NOW() - INTERVAL 10 MINUTE)");
    }

    private function turn(int $session, ScriptedAgent $agent): array
    {
        $manager = new ChatManager($this->db, $agent);

        // Keep this deterministic and offline: only tools that touch the database are scripted, and none
        // of them may reach the network.
        return $manager->process($session, 'a scripted request', null, null, 'medium', null);
    }

    private function evidenceRows(int $session): int
    {
        return (int)($this->db->query(
            "SELECT COUNT(*) c FROM chat_history WHERE session_id = ? AND message_type = 'data_fetching'",
            [$session]
        )[0]['c'] ?? 0);
    }

    private function reminderRow(int $session): int
    {
        return (int)($this->db->query(
            "SELECT COUNT(*) c FROM chat_history WHERE session_id = ? AND turn_reminder IS NOT NULL",
            [$session]
        )[0]['c'] ?? 0);
    }

    private function events(string $type): int
    {
        return (int)($this->db->query(
            "SELECT COUNT(*) c FROM app_events WHERE event_type = ? AND created_at > (NOW() - INTERVAL 5 MINUTE)",
            [$type]
        )[0]['c'] ?? 0);
    }

    /** Calls the dispatch guard refused for having no executable arguments. */
    private function droppedCalls(): int
    {
        return (int)($this->db->query(
            "SELECT COUNT(*) c FROM app_events
             WHERE event_type = 'llm_tool_no_match' AND context LIKE '%empty_args%'
               AND created_at > (NOW() - INTERVAL 5 MINUTE)"
        )[0]['c'] ?? 0);
    }

    /** Whether the turn's assistant row records that tools were used. */
    private function usedTools(int $session): bool
    {
        $rows = $this->db->query(
            "SELECT had_tool_calls FROM chat_history WHERE session_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1",
            [$session]
        );

        return !empty($rows) && (int)($rows[0]['had_tool_calls'] ?? 0) === 1;
    }

    private function call(string $name, array $args): array
    {
        return ['function' => ['name' => $name, 'arguments' => json_encode($args)]];
    }

    /** @param array<int, array<string, mixed>> $script */
    private function scripted(array $script): ScriptedAgent
    {
        $agent = new ScriptedAgent();
        $agent->script = $script;

        return $agent;
    }

    private function test(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
            return;
        }
        $this->failed++;
        $this->failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
        echo "  [FAIL] {$label}\n";
    }
}

/**
 * An agent that says exactly what the script tells it to, and records what it was shown. Nothing here
 * reaches a network or a model: chatToolCapable returns the scripted step, chat (the forced answer
 * pass) returns a canned string.
 */
final class ScriptedAgent extends AgentManager
{
    /** @var array<int, array<string, mixed>> */
    public array $script = [];
    public int $passes = 0;
    public int $plainChats = 0;
    public string $plainAnswer = 'forced answer';
    /** @var array<int, array<int, string>> md5 of each message per pass, to prove the prefix is stable */
    public array $messageHashes = [];

    public function chatToolCapable(
        array $messages,
        array $tools,
        string $toolChoice,
        callable $streamCallback = null,
        ?float $temperature = null,
        ?string $purpose = null,
        ?int $maxTokens = null,
        ?string $mode = null,
        ?string $effort = null
    ): array {
        $this->passes++;
        $this->messageHashes[] = array_map(static fn($m): string => md5(json_encode($m)), $messages);

        $step = $this->script[$this->passes - 1] ?? ['content' => 'nothing scripted for this pass'];
        $content = (string)($step['content'] ?? '');
        if ($content !== '' && $streamCallback !== null) {
            $streamCallback($content, 'content');
        }

        return [
            'tool_calls' => $step['tool_calls'] ?? [],
            'content' => $content,
            'finish_reason' => empty($step['tool_calls']) ? 'stop' : 'tool_calls',
        ];
    }

    public function chat(
        array $messages,
        bool $stream = true,
        callable $streamCallback = null,
        ?float $temperature = null,
        ?string $purpose = null,
        ?string $mode = null,
        ?string $effort = null,
        ?int $maxTokens = null,
        ?array $tools = null,
        ?string $toolChoice = null
    ): string {
        $this->plainChats++;
        if ($streamCallback !== null) {
            $streamCallback($this->plainAnswer, 'content');
        }

        return $this->plainAnswer;
    }
}
