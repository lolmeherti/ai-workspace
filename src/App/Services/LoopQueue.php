<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Queue 1 — the loop queue: the rounds a RUNNING TURN owes, in order.
 *
 * The unit of work is the whole model loop, not one generation. A turn's first pass either answers (no tool calls) or
 * comes back flagged as a tool turn; a tool turn appends exactly one next round here, that round runs (the tools
 * execute, the model is re-prompted), and the loop continues until the tools are closed and the answer pass is written.
 *
 * Two rules give this class its reason to exist:
 *
 *   1. **Nothing but a flagged tool turn can add work to the turn.** There is deliberately no method that takes user
 *      text: a message sent while the loop runs cannot enter it, which is a property of the API rather than a check
 *      that could be forgotten. The message waits outside (queue 3).
 *   2. **The turn is not over when tokens stop.** It is over when this queue is empty AND the answer has been written;
 *      a stream ending between rounds says nothing about the turn.
 *
 * Stop is checked at round boundaries: it drops the rounds that have not run (the user asked to stop the tool-call
 * loop) and keeps whatever the turn has already produced — a generation in flight is never interrupted.
 *
 * One queue per conversation: a second turn cannot open while one owns the conversation (`open()` refuses).
 *
 * See `.hermes/plans/evidence/2026-10-11_queue-boundaries-and-loop-edge-cases.md` (build order: queue 1).
 */
final class LoopQueue
{
    public const NEXT_ROUND = 'round';
    public const NEXT_ANSWER = 'answer';

    /** @var array<int,array{kind:string,seq:int,calls:int}> */
    private array $pending = [];

    private int $seq = 0;
    private int $toolRounds = 0;
    private bool $toolsClosed = false;
    private bool $stopped = false;
    private bool $answerWritten = false;
    private bool $answeredWithoutTools = false;

    /** @var array<int,string> who owns the conversation right now (in-process; the cross-process view is ActiveTurns) */
    private static array $owners = [];

    private function __construct(private int $sessionId, private int $maxRounds = 3)
    {
    }

    /**
     * Open the loop for a conversation. Refuses when another turn already owns it — two turns in one conversation would
     * assemble the transcript from half-written state, which is what produced the incoherent batch answer once.
     */
    public static function open(int $sessionId, int $maxRounds = 3): ?self
    {
        if (isset(self::$owners[$sessionId])) {
            return null;
        }
        self::$owners[$sessionId] = 'turn';

        // No round is seeded. The pass that opens a turn runs *outside* the queue — the loop only enters for the rounds
        // that follow a flagged tool turn — so seeding one made every single turn report a pending round nobody would
        // ever consume. The shadow comparison caught exactly that on the first live turn (four `loop_queue_verdict`
        // warnings, each with `queue_pending: 1`), which is what the shadow step is for.
        return new self($sessionId, $maxRounds);
    }

    public function close(): void
    {
        unset(self::$owners[$this->sessionId]);
    }

    /** Close whatever turn owns this conversation — the safety net for a throw in the middle of a turn. */
    public static function closeFor(int $sessionId): void
    {
        unset(self::$owners[$sessionId]);
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    /**
     * A flagged tool turn arrived from the model: append its round. Returns false when the round cannot be added —
     * tools are closed, the turn was stopped, or the call carried no tool calls (then the pass WAS the answer).
     *
     * @param array<int,mixed> $toolCalls
     */
    public function appendToolRound(array $toolCalls): bool
    {
        if ($toolCalls === []) {
            // A pass with no tool calls is the answer pass: the loop is over as soon as it is written.
            $this->answeredWithoutTools = true;

            return false;
        }
        if ($this->stopped || $this->toolsClosed) {
            return false;
        }
        if ($this->toolRounds >= $this->maxRounds) {
            $this->toolsClosed = true;

            return false;
        }

        $this->toolRounds++;
        $this->pending[] = ['kind' => self::NEXT_ROUND, 'seq' => ++$this->seq, 'calls' => count($toolCalls)];
        if ($this->toolRounds >= $this->maxRounds) {
            // The last tool round is spent: the loop now owes exactly one answer pass and nothing else.
            $this->toolsClosed = true;
        }

        return true;
    }

    /** The model is told to answer no further tool calls (the reminder row / a forced answer). */
    public function closeTools(): void
    {
        $this->toolsClosed = true;
    }

    /** What the loop must do next, or null when the turn is finished. */
    public function next(): ?string
    {
        if ($this->pending !== []) {
            return self::NEXT_ROUND;
        }
        if ($this->answerWritten) {
            return null;
        }
        if ($this->stopped) {
            // Nothing left to run and no answer owed: the turn ends with whatever the last round produced.
            return null;
        }

        return self::NEXT_ANSWER;
    }

    /** The round that was handed out by next() has run. */
    public function markRoundDone(): void
    {
        array_shift($this->pending);
    }

    /** The answer pass is written (the assistant row exists). The turn is over. */
    public function markAnswerWritten(): void
    {
        $this->answerWritten = true;
    }

    /**
     * The stop control: drop the rounds that have not run, keep what already ran. Never interrupts a generation.
     *
     * @return int how many rounds were dropped
     */
    public function requestStop(): int
    {
        $this->stopped = true;
        $dropped = count($this->pending);
        $this->pending = [];

        return $dropped;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function isFinished(): bool
    {
        return $this->next() === null;
    }

    public function toolRounds(): int
    {
        return $this->toolRounds;
    }

    public function pendingRounds(): int
    {
        return count($this->pending);
    }

    public function toolsClosed(): bool
    {
        return $this->toolsClosed;
    }

    public function answeredWithoutTools(): bool
    {
        return $this->answeredWithoutTools;
    }

    /** @return array<int,array{kind:string,seq:int,calls:int}> */
    public function transcript(): array
    {
        return $this->pending;
    }
}
