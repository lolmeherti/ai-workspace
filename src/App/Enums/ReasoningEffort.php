<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reasoning effort vocabulary — ONE closed set, used for every comparison in the web layer.
 *
 * Source of truth: `models.json`, `families.<family>.reasoning.reasoning_effort_values` — the members below are the
 * union of what the families declare, and each family's own set is mirrored by `ModelFamily::reasoningEffortValues()`
 * (a binary family declares `on`/`off`, a graduated one its ladder). An engine alias (`none`, `xhigh`, `minimal`) is a
 * wire value, not a declared value, and resolves to null here.
 *
 * Never compare raw effort strings: use `fromSlider()` and `atLeast()`.
 */
enum ReasoningEffort: string
{
    case Off = 'off';
    case On = 'on';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Ordered WITHIN a family's own set — binary: off < on; graduated: low < medium < high. The sets never meet, so one
     * linear rank answers "is this the family's top value".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::On => 4,
        };
    }

    /**
     * A stored setting → the enum, or null when it is not a declared effort level. `off` lands here by design: it is the
     * mode, not a level.
     */
    public static function fromSlider(?string $stored): ?self
    {
        return $stored === null ? null : self::tryFrom($stored);
    }

    /** True when this level is at or above $other. */
    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Every member as its string value — what the parity test compares against `models.json`. */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
