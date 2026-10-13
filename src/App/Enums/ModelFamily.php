<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The model families — the same closed set Go declares in `internal/models/family.go`, and the same keys `models.json`
 * uses under `families`.
 *
 * Every catalog model carries a `family`, and a model without one fails the catalog test. This enum is how the web layer
 * names a family; it never compares family strings by hand, and it never derives a family from a model name.
 */
enum ModelFamily: string
{
    case Gemma = 'gemma';
    case Qwen = 'qwen';
    case Bonsai = 'bonsai';

    /** The family a runtime ID belongs to — mirrors Go's `familyByRuntime`. Unknown runtime ⇒ null, never a guess. */
    public static function fromRuntime(?string $runtime): ?self
    {
        return match ((string)$runtime) {
            'gemma4' => self::Gemma,
            'qwen38', 'qwen35' => self::Qwen,
            'bonsai' => self::Bonsai,
            default => null,
        };
    }

    /**
     * The family's reasoning values, mirroring `families.<family>.reasoning.reasoning_effort_values` in models.json —
     * the source of truth. A binary family is on/off; a graduated family is its ladder.
     */
    public function reasoningEffortValues(): array
    {
        return match ($this) {
            self::Gemma, self::Bonsai => [ReasoningEffort::Off, ReasoningEffort::On],
            self::Qwen => [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High],
        };
    }

    /** Every member as its string value — what the parity test compares against `models.json`. */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
