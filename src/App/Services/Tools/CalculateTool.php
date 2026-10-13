<?php

declare(strict_types=1);

namespace App\Services\Tools;

/**
 * A refused operation, carrying the JSON the model should read.
 *
 * Validation failures travel as this rather than as return values: "the value" and "an error" cannot share a return type
 * without one of them being guessed at every call site (the first version of this tool did exactly that, and a valid
 * value was handed back to the model as an error). It is caught in one place — `run()` — and never escapes the tool.
 */
final class CalculationError extends \RuntimeException
{
    public function __construct(public readonly string $payload)
    {
        parent::__construct($payload);
    }
}

/**
 * The calculation tool: one wrapper, an allowlist of operations, and structured errors.
 *
 * Deliberately NOT an arithmetic engine and NOT an expression parser. The model picks an operation name and supplies
 * values as decimal strings; nothing else in the call is ever interpreted as code. The dispatch IS the allowlist —
 * `self::OPERATIONS[$operation]` — so an operation the model invents falls through to an error and cannot reach anything.
 *
 * Money must not be guessed: `bcscale()` is never called (it is process-global and mutable, so one caller could silently
 * change the precision of another's arithmetic), every operation passes its own scale, and the applied scale and rounding
 * are always reported back in the response.
 *
 * Structured errors go out as tool output — the model reads them and can correct itself. Nothing throws out of here, so a
 * bad call cannot end a turn's stream (the failure mode ToolExecutionService::normalizeToolArguments was hardened
 * against).
 *
 * See `.hermes/plans/tools/2026-10-11_calculator-tool.md`.
 */
final class CalculateTool
{
    /** The dispatch allowlist: operation name → the keys it accepts. Nothing is dispatched that is not a key here. */
    private const OPERATIONS = [
        'add' => ['values', 'precision'],
        'subtract' => ['values', 'precision'],
        'multiply' => ['values', 'precision'],
        'divide' => ['values', 'precision'],
        'percent_of' => ['value', 'percent', 'precision'],
        'percent_change' => ['value', 'percent', 'direction', 'precision'],
        'total' => ['values', 'precision', 'rounding'],
        'days_between' => ['from', 'to'],
        'date_add' => ['date', 'days', 'weeks', 'months', 'timezone'],
        'date_subtract' => ['date', 'days', 'weeks', 'months', 'timezone'],
    ];

    private const DEFAULT_PRECISION = 2;
    private const DIVIDE_PRECISION = 12;
    private const MAX_PRECISION = 12;
    private const MAX_VALUES = 20;

    /**
     * A decimal string, and nothing else: no exponent, no thousands separator, no locale comma, no NaN/INF. Bounded to 30
     * integer digits and 12 decimals so a ten-thousand-digit "number" cannot reach bcmath.
     */
    private const NUMBER_PATTERN = '/^-?\d{1,30}(\.\d{1,12})?$/';

    /**
     * @param array<string,mixed> $toolData
     * @param array<int,mixed>    $messages
     */
    public function execute(array $toolData, int $sessionId, array $messages = [], ?callable $emit = null, string $cleanJson = ''): string
    {
        try {
            return $this->run($toolData);
        } catch (CalculationError $e) {
            return $e->payload;
        } catch (\Throwable $e) {
            // Belt and braces: this class aims never to throw, and if it ever does the model still gets a result it can
            // act on rather than a dead stream.
            return $this->error('internal_error', (string)($toolData['operation'] ?? ''), 'The calculation could not be completed: ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $toolData */
    private function run(array $toolData): string
    {
        $operation = $toolData['operation'] ?? null;
        if (!is_string($operation) || !array_key_exists($operation, self::OPERATIONS)) {
            $this->fail('unknown_operation', is_string($operation) ? $operation : '',
                'Unknown operation. Use one of: ' . implode(', ', array_keys(self::OPERATIONS)) . '.');
        }

        $allowed = self::OPERATIONS[$operation];
        $extra = array_diff(array_keys($toolData), array_merge(['tool', 'operation'], $allowed));
        if ($extra !== []) {
            // Loud, never ignored: a call carrying a key this operation does not declare is a malformed call, and quietly
            // accepting it is how a wrong answer looks like a working one.
            $this->fail('unknown_argument', $operation, 'Argument(s) not accepted by this operation: ' . implode(', ', $extra) . '.');
        }

        return match ($operation) {
            'add', 'subtract', 'multiply', 'divide' => $this->arithmetic($operation, $toolData),
            'percent_of' => $this->percentOf($toolData),
            'percent_change' => $this->percentChange($toolData),
            'total' => $this->total($toolData),
            'days_between' => $this->daysBetween($toolData),
            'date_add', 'date_subtract' => $this->dateShift($operation, $toolData),
        };
    }

    // ── numeric operations ───────────────────────────────────────────────────

    /** @param array<string,mixed> $toolData */
    private function arithmetic(string $operation, array $toolData): string
    {
        $values = $this->values($toolData, $operation);
        if (count($values) < 2) {
            $this->fail('missing_argument', $operation, 'This operation needs at least two values.');
        }
        $precision = $this->precision($toolData, $operation, $operation === 'divide' ? self::DIVIDE_PRECISION : self::DEFAULT_PRECISION);

        if ($operation === 'divide') {
            $scale = max($precision, self::DIVIDE_PRECISION);
            // Refusals first, prerequisites second: a zero divisor is the model's mistake to hear about, and it must be
            // reported as that even on an image where the arithmetic itself cannot run.
            $this->refuseZeroDivisor($values);
            $this->requireBcmath();
            $result = array_shift($values);
            foreach ($values as $value) {
                $result = bcdiv($result, $value, $scale);
            }

            return $this->ok($operation, $result, ['precision' => $scale, 'rounding' => 'none']);
        }

        // The arithmetic below is bcmath; the guard comes after the refusals above, so a mistake the model made is reported
        // as the mistake it is rather than as a missing extension.
        $this->requireBcmath();
        $result = array_shift($values);
        foreach ($values as $value) {
            $result = match ($operation) {
                'add' => bcadd($result, $value, $precision),
                'subtract' => bcsub($result, $value, $precision),
                'multiply' => bcmul($result, $value, $precision),
            };
        }

        return $this->ok($operation, $result, ['precision' => $precision, 'rounding' => 'none']);
    }

    /** @param array<string,mixed> $toolData */
    private function percentOf(array $toolData): string
    {
        $value = $this->number($toolData, 'value');
        $percent = $this->number($toolData, 'percent');
        $precision = $this->precision($toolData, 'percent_of', self::DEFAULT_PRECISION);
        $this->requireBcmath();

        // percent * value / 100, at a scale wide enough that the rounding step is the only rounding that happens.
        $scale = max($precision + 4, 8);
        $result = bcdiv(bcmul($percent, $value, $scale), '100', $scale);

        return $this->ok('percent_of', $this->roundHalfUp($result, $precision), ['precision' => $precision, 'rounding' => 'half-up']);
    }

    /** @param array<string,mixed> $toolData */
    private function percentChange(array $toolData): string
    {
        $value = $this->number($toolData, 'value');
        $percent = $this->number($toolData, 'percent');
        $direction = $toolData['direction'] ?? null;
        if ($direction !== 'increase' && $direction !== 'decrease') {
            $this->fail('invalid_direction', 'percent_change',
                'direction must be "increase" or "decrease" — say which one, do not assume it.');
        }
        $precision = $this->precision($toolData, 'percent_change', self::DEFAULT_PRECISION);
        $this->requireBcmath();

        $scale = max($precision + 4, 8);
        $delta = bcdiv(bcmul($percent, $value, $scale), '100', $scale);
        $result = $direction === 'increase'
            ? bcadd($value, $delta, $scale)
            : bcsub($value, $delta, $scale);

        return $this->ok('percent_change', $this->roundHalfUp($result, $precision), [
            'precision' => $precision,
            'rounding' => 'half-up',
            'direction' => $direction,
        ]);
    }

    /** @param array<string,mixed> $toolData */
    private function total(array $toolData): string
    {
        $values = $this->values($toolData, 'total');

        // No default on purpose: money must not inherit an arbitrary precision.
        if (!array_key_exists('precision', $toolData)) {
            $this->fail('missing_argument', 'total', 'precision is required for a total: state the decimal places.');
        }
        $precision = $this->precision($toolData, 'total', self::DEFAULT_PRECISION);
        $rounding = $toolData['rounding'] ?? 'half-up';
        if ($rounding !== 'half-up') {
            $this->fail('invalid_rounding', 'total', 'Only "half-up" rounding is accepted.');
        }
        $this->requireBcmath();

        $scale = max($precision + 4, 8);
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $value, $scale);
        }

        return $this->ok('total', $this->roundHalfUp($sum, $precision), ['precision' => $precision, 'rounding' => 'half-up']);
    }

    // ── date operations ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $toolData */
    private function daysBetween(array $toolData): string
    {
        $from = $this->parseDate($toolData, 'from');
        $to = $this->parseDate($toolData, 'to');

        $diff = $from->diff($to);
        $days = (int)$diff->days * ($diff->invert === 1 ? -1 : 1);

        return $this->ok('days_between', (string)$days, [
            'unit' => 'days',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => $this->timezone(),
        ]);
    }

    /** @param array<string,mixed> $toolData */
    private function dateShift(string $operation, array $toolData): string
    {
        $date = $this->parseDate($toolData, 'date');

        $given = array_values(array_filter(['days', 'weeks', 'months'], static fn(string $key): bool => array_key_exists($key, $toolData)));
        if (count($given) !== 1) {
            $this->fail('missing_argument', $operation, 'Give exactly one of days, weeks or months.');
        }
        $unit = $given[0];
        $raw = $toolData[$unit];
        if (is_string($raw) && preg_match('/^-?\d{1,6}$/', $raw) === 1) {
            $raw = (int)$raw;
        }
        if (!is_int($raw)) {
            $this->fail('invalid_number', $operation, "{$unit} must be a whole number.");
        }
        $amount = $operation === 'date_subtract' ? -$raw : $raw;

        $clamped = false;
        if ($unit === 'months') {
            [$result, $clamped] = $this->addMonthsClamped($date, $amount);
        } else {
            $days = $unit === 'weeks' ? $amount * 7 : $amount;
            $result = $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
        }

        $payload = ['from' => $date->format('Y-m-d'), $unit => $toolData[$unit], 'timezone' => $this->timezone()];
        if ($unit === 'months') {
            // Reported, not hidden: 31 January plus a month is not 31 February, and the model should know it moved.
            $payload['clamped'] = $clamped;
        }

        return $this->ok($operation, $result->format('Y-m-d'), $payload);
    }

    /**
     * Add months with the day clamped to the target month's length (31 Jan + 1 month = 28/29 Feb).
     *
     * Written rather than taken from Carbon's `addMonthsNoOverflow`: Carbon is only a transitive dependency of the IMAP
     * library and is not declared in composer.json, so this tool does not lean on it. `modify('+1 month')` cannot be used
     * either — on 31 January it rolls forward into March.
     *
     * @return array{0:\DateTimeImmutable,1:bool}
     */
    private function addMonthsClamped(\DateTimeImmutable $date, int $months): array
    {
        $day = (int)$date->format('j');
        $firstOfTarget = $date->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $newDay = min($day, (int)$firstOfTarget->format('t'));
        $result = $firstOfTarget->setDate((int)$firstOfTarget->format('Y'), (int)$firstOfTarget->format('n'), $newDay);

        return [$result, $newDay !== $day];
    }

    // ── validation helpers (each returns its value, or refuses by throwing) ─────────────────

    /**
     * @param array<string,mixed> $toolData
     * @return array<int,string>
     */
    private function values(array $toolData, string $operation): array
    {
        $values = $toolData['values'] ?? null;
        if (!is_array($values) || $values === []) {
            $this->fail('missing_argument', $operation, 'values must be a non-empty list of decimal strings.');
        }
        if (count($values) > self::MAX_VALUES) {
            $this->fail('too_many_values', $operation, 'At most ' . self::MAX_VALUES . ' values.');
        }

        $checked = [];
        foreach ($values as $value) {
            $number = $this->asNumber($value);
            if ($number === null) {
                $this->fail('invalid_number', $operation, 'Not a plain decimal number: '
                    . (is_scalar($value) ? (string)$value : gettype($value))
                    . '. Use digits with an optional single dot, e.g. "150.00".');
            }
            $checked[] = $number;
        }

        return $checked;
    }

    /** @param array<string,mixed> $toolData */
    private function number(array $toolData, string $key): string
    {
        if (!array_key_exists($key, $toolData)) {
            $this->fail('missing_argument', (string)($toolData['operation'] ?? ''), "{$key} is required.");
        }
        $number = $this->asNumber($toolData[$key]);
        if ($number === null) {
            $this->fail('invalid_number', (string)($toolData['operation'] ?? ''), "{$key} must be a plain decimal number, e.g. \"150.00\".");
        }

        return $number;
    }

    /** A decimal string as the spec allows it, or null. */
    private function asNumber(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            // A float that survived the JSON decode is still exactly representable as its shortest decimal string;
            // anything with an exponent or a trailing tail beyond the pattern is rejected by the check below.
            $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
            if ($value === '' || $value === '-') {
                $value = '0';
            }
        }
        if (!is_string($value) || preg_match(self::NUMBER_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /** @param array<string,mixed> $toolData */
    private function precision(array $toolData, string $operation, int $default): int
    {
        if (!array_key_exists('precision', $toolData)) {
            return $default;
        }
        $precision = $toolData['precision'];
        if (is_string($precision) && preg_match('/^\d{1,2}$/', $precision) === 1) {
            $precision = (int)$precision;
        }
        if (!is_int($precision) || $precision < 0 || $precision > self::MAX_PRECISION) {
            $this->fail('precision_out_of_range', $operation,
                'precision must be a whole number between 0 and ' . self::MAX_PRECISION . '.');
        }

        return $precision;
    }

    /**
     * Refuse a zero divisor before any division happens.
     *
     * Checked as text rather than with `bccomp`: the values are already validated decimal strings, so "is this zero" is
     * exact either way — and this way the refusal works (and is tested) even on an image without bcmath, where `bcdiv`
     * would throw instead of answering.
     *
     * @param array<int,string> $values
     */
    private function refuseZeroDivisor(array $values): void
    {
        foreach (array_slice($values, 1) as $value) {
            if (preg_match('/^-?0(\.0+)?$/', $value) === 1) {
                $this->fail('division_by_zero', 'divide', 'The divisor is zero.');
            }
        }
    }

    /** @param array<string,mixed> $toolData */
    private function parseDate(array $toolData, string $key): \DateTimeImmutable
    {
        if (!array_key_exists($key, $toolData)) {
            $this->fail('missing_argument', (string)($toolData['operation'] ?? ''), "{$key} is required (ISO Y-m-d).");
        }
        $raw = (string)$toolData[$key];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        // Round-trip: PHP happily rolls 2026-02-30 forward into March, which would silently answer a different question.
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            $this->fail('invalid_date', (string)($toolData['operation'] ?? ''), "Not a real date: {$raw}. Use ISO Y-m-d.");
        }

        return $date;
    }

    /**
     * bcmath is an image prerequisite — the arithmetic is exact decimal, not float. Its absence is reported as an error
     * rather than becoming a fatal, so validation and the date operations still work on an image that lacks it.
     */
    private function requireBcmath(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->fail('internal_error', '', 'The bcmath extension is not available in this image, so exact decimal arithmetic cannot run.');
        }
    }

    /** The same clock the prompt's "[sent at …]" stamp uses, so the tool and the prompt cannot disagree about today. */
    private function timezone(): string
    {
        return (string)\App\Config::get('TZ', date_default_timezone_get());
    }

    /**
     * Half-up, away from zero, at the given scale.
     *
     * bcmath truncates at the scale, so adding half a unit before truncating is half-up. `bcround()` would do this in
     * PHP 8.4; this image is 8.3 (probed), so it is written here.
     */
    private function roundHalfUp(string $number, int $scale): string
    {
        $half = ($number !== '' && $number[0] === '-' ? '-' : '') . '0.' . str_repeat('0', $scale) . '5';

        return bcadd($number, $half, $scale);
    }

    /** @param array<string,mixed> $extra */
    private function ok(string $operation, string $result, array $extra = []): string
    {
        return (string)json_encode(array_merge([
            'ok' => true,
            'operation' => $operation,
            'result' => $result,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(string $code, string $operation, string $message): string
    {
        return (string)json_encode([
            'ok' => false,
            'error' => $code,
            'operation' => $operation,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Refuse: the payload reaches the model through run()'s catch, and never escapes the tool. */
    private function fail(string $code, string $operation, string $message): never
    {
        throw new CalculationError($this->error($code, $operation, $message));
    }
}
