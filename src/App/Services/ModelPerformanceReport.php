<?php

namespace App\Services;

/**
 * Pure per-model aggregation for /models.php. No DB, no LLM — takes raw rows
 * and reduces them to per-model speed / fault / satisfaction columns so it can
 * be unit-tested against fixtures.
 */
class ModelPerformanceReport
{
    /**
     * LLM-attributable faults: the model failed to output a proper tool call or
     * a proper answer. Everything else at warn/error/critical (connection
     * errors, tool execution failures, bridge/search/job noise) is system/infra.
     */
    public const MODEL_FAULT_EVENTS = [
        'llm_tool_parse_error',
        'llm_tool_no_match',
        'llm_partial_response',
        'llm_empty_answer_firstpass',
        'llm_empty_answer_thinking_exhausted',
        'content_before_tool',
    ];

    /**
     * @param array<int, array<string, mixed>> $historyRows assistant rows: model, perf_metrics, rating, rating_reason
     * @param array<int, array<string, mixed>> $eventRows  warn/error/critical events: model, event_type
     * @return array<int, array<string, mixed>>
     */
    public function build(array $historyRows, array $eventRows): array
    {
        $errorsByModel = [];
        foreach ($eventRows as $row) {
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') {
                continue;
            }
            $type = (string)($row['event_type'] ?? '');
            $errorsByModel[$model][$type] = ($errorsByModel[$model][$type] ?? 0) + 1;
        }

        $models = [];
        foreach ($historyRows as $row) {
            $m = trim((string)($row['model'] ?? ''));
            if ($m !== '') {
                $models[$m] = true;
            }
        }
        foreach (array_keys($errorsByModel) as $m) {
            $models[$m] = true;
        }

        $out = [];
        foreach (array_keys($models) as $model) {
            $out[] = $this->buildOne($model, $historyRows, $errorsByModel[$model] ?? []);
        }
        usort($out, fn($a, $b) => ($b['turns'] <=> $a['turns']) ?: strcmp($a['model'], $b['model']));
        return $out;
    }

    /**
     * Which call in a turn carries the answer content. Omits tool-decision
     * emission entirely (a `firstpass` call with no content on a tool turn).
     */
    public function pickAnswerCall(array $calls): ?array
    {
        foreach ($calls as $c) {
            if (($c['purpose'] ?? '') === 'answer') {
                return $c;
            }
        }
        foreach ($calls as $c) {
            if (($c['purpose'] ?? '') === 'firstpass' && (int)($c['content_tok'] ?? 0) > 0) {
                return $c;
            }
        }
        foreach ($calls as $c) {
            if ((int)($c['content_tok'] ?? 0) > 0) {
                return $c;
            }
        }
        return null;
    }

    private function buildOne(string $model, array $historyRows, array $errorCounts): array
    {
        $decode = [];
        $prefill = [];
        $cache = [];
        $turns = 0;
        $up = 0;
        $down = 0;
        $downReasons = [];

        foreach ($historyRows as $row) {
            if (trim((string)($row['model'] ?? '')) !== $model) {
                continue;
            }
            $perf = $row['perf_metrics'] ?? null;
            if (is_string($perf)) {
                $perf = json_decode($perf, true);
            }
            $calls = (is_array($perf) && is_array($perf['calls'] ?? null)) ? $perf['calls'] : [];
            $call = $this->pickAnswerCall($calls);
            if ($call !== null) {
                $turns++;
                $d = (float)($call['pred_tps'] ?? 0);
                if ($d > 0) {
                    $decode[] = $d;
                }
                $p = (float)($call['prompt_tps'] ?? 0);
                if ($p > 0) {
                    $prefill[] = $p;
                }
                $pt = (int)($call['prompt_tokens'] ?? 0);
                if ($pt > 0) {
                    $cache[] = ((int)($call['cache_n'] ?? 0) / $pt) * 100;
                }
            }

            $rating = $row['rating'] ?? null;
            if ($rating === null) {
                continue;
            }
            $rating = (int)$rating;
            if ($rating === 1) {
                $up++;
            } elseif ($rating === 0) {
                $down++;
                $reason = trim((string)($row['rating_reason'] ?? ''));
                if ($reason !== '') {
                    $downReasons[$reason] = ($downReasons[$reason] ?? 0) + 1;
                }
            }
        }

        $modelFaults = 0;
        $otherErrors = 0;
        $faultBreakdown = [];
        foreach ($errorCounts as $type => $count) {
            if (in_array($type, self::MODEL_FAULT_EVENTS, true)) {
                $modelFaults += $count;
                $faultBreakdown[$type] = $count;
            } else {
                $otherErrors += $count;
            }
        }

        $rated = $up + $down;
        return [
            'model' => $model,
            'turns' => $turns,
            'avg_decode_tps' => $this->avg($decode),
            'avg_prefill_tps' => $this->avg($prefill),
            'avg_cache_pct' => $this->avg($cache),
            'model_faults' => $modelFaults,
            'fault_breakdown' => $faultBreakdown,
            'other_errors' => $otherErrors,
            'up' => $up,
            'down' => $down,
            'down_reasons' => $downReasons,
            'rated' => $rated,
            'pct_positive' => $rated > 0 ? ($up / $rated) * 100 : null,
        ];
    }

    private function avg(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        return array_sum($values) / count($values);
    }
}
