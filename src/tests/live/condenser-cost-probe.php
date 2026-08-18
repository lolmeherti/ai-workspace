<?php

declare(strict_types=1);

/*
 * Phase 4 gate probe — SourceCondenser call multiplicity + cost + sparsity
 * (Gates 2 and 3). LIVE: fires real LLM calls against llama.cpp.
 *
 * Gate 2 (call multiplicity / cost): builds N synthetic sources with realistic
 * chunk text and runs the ACTUAL SourceCondenser::condense(), counting LLM
 * calls via a CountingAgent subclass and recording per-call input-token
 * estimate, server-reported prompt/completion tokens, and wall-clock latency.
 * Confirms whether always-condense means 1 inference or N per search.
 *
 * Gate 3 (query-aware sparsity): the fixtures intentionally mix a small set of
 * answer facts (who won the Finals) with a large amount of query-irrelevant
 * detail (player stats, advanced metrics, play-by-play, standings). The ledger
 * the condenser returns is printed so sparsity can be judged directly.
 *
 * Requires llama.cpp running. Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/condenser-cost-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\SourceCondenser;
use App\Search\WebChunk;

Config::load(dirname(__DIR__, 2));

/** Counts chat() calls and records cost without altering AgentManager behavior. */
final class CountingAgent extends AgentManager
{
    public int $calls = 0;
    /** @var array<int, array<string,mixed>> */
    public array $callLog = [];

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): string
    {
        $this->calls++;

        $inTokens = 0;
        foreach ($messages as $m) {
            $c = is_array($m['content'] ?? null) ? ($m['content'][0]['text'] ?? '') : ($m['content'] ?? '');
            $inTokens += (int)(mb_strlen((string) $c) / 4);
        }

        $t0 = microtime(true);
        $out = parent::chat($messages, $stream, $streamCallback, $temperature);
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        $this->callLog[] = [
            'in_tokens_est'       => $inTokens,
            'prompt_tokens'       => $this->lastUsage['prompt_tokens'] ?? null,
            'completion_tokens'   => $this->lastUsage['completion_tokens'] ?? null,
            'total_tokens'        => $this->lastUsage['total_tokens'] ?? null,
            'elapsed_ms'          => $elapsedMs,
            'out_chars'           => strlen($out),
        ];

        return $out;
    }
}

/** @return WebChunk[] */
function fixtures(): array
{
    // Source 1 — Basketball-Reference Finals page: answer facts + heavy stat noise.
    $s1 = [
        'The 2026 NBA Finals was won by the New York Knicks, who defeated the '
        . 'San Antonio Spurs four games to one. The series concluded on June 14, '
        . '2026 with a Game 5 victory by the Knicks, 112-104.',
        'Finals MVP Jalen Brunson averaged 28.4 points, 7.2 assists and 5.1 '
        . 'rebounds across the five games of the series.',
        'Player per-game stats: Karl-Anthony Towns 24.1 pts 9.8 reb 2.1 ast; '
        . 'OG Anunoby 12.3 pts 4.0 reb; Josh Hart 9.2 pts 11.1 reb 6.4 ast; '
        . 'Donte DiVincenzo 10.1 pts 3.3 ast; Miles McBride 7.8 pts. Advanced '
        . 'metrics: PER 28.4, WS/48 .210, BPM +6.2, VORP 1.9, TS% .612.',
        'Regular season standings: Knicks 54-28 (1st Eastern Conference), Spurs '
        . '51-31 (1st Western Conference). Playoff bracket: Knicks beat Celtics '
        . '4-2, Cavaliers 4-1; Spurs beat Thunder 4-3, Timberwolves 4-2.',
    ];

    // Source 2 — ESPN Game 3 recap: one relevant result + play-by-play noise.
    $s2 = [
        'SAN ANTONIO — The Spurs beat the Knicks 115-111 in Game 3 of the Finals '
        . 'to cut the series deficit to 2-1. Victor Wembanyama scored 38 points '
        . 'with 14 rebounds and 6 blocks.',
        'Play by play: Q1 12:00 jump ball won by Spurs; Q1 11:45 Wembanyama '
        . 'blocks Brunson; Q1 11:20 Knicks turnover; Q1 11:04 Sochan dunk; Q1 '
        . '10:41 Towns three-pointer; Q1 10:20 Spurs timeout.',
    ];

    // Source 3 — analytics/notes page: all noise, no direct answer.
    $s3 = [
        'The Knicks posted a net rating of +8.4 during the Finals; their '
        . 'half-court offense generated 1.12 points per possession in the '
        . 'fourth quarters of the series.',
        'Injury report: New York listed no players on the Finals injury report. '
        . 'San Antonio guard Tre Jones was listed as probable for Game 4.',
    ];

    $mk = function (string $src, string $cid, string $url, string $title, string $domain, string $text, int $pos): WebChunk {
        return new WebChunk(
            $src, $cid, $url, $url, $title, $domain,
            null, null, '2026-06-15T00:00:00Z', [], 'entity',
            $text, $pos, null, null, null, null
        );
    };

    $chunks = [];
    $chunks[] = $mk('S1', 'S1-C1', 'https://example.com/bball-ref-finals', 'Basketball-Reference — 2026 NBA Finals', 'basketball-reference.com', $s1[0], 1);
    $chunks[] = $mk('S1', 'S1-C2', 'https://example.com/bball-ref-finals', 'Basketball-Reference — 2026 NBA Finals', 'basketball-reference.com', $s1[1], 2);
    $chunks[] = $mk('S1', 'S1-C3', 'https://example.com/bball-ref-finals', 'Basketball-Reference — 2026 NBA Finals', 'basketball-reference.com', $s1[2], 3);
    $chunks[] = $mk('S1', 'S1-C4', 'https://example.com/bball-ref-finals', 'Basketball-Reference — 2026 NBA Finals', 'basketball-reference.com', $s1[3], 4);
    $chunks[] = $mk('S2', 'S2-C1', 'https://example.com/espn-game3', 'ESPN — Spurs 115-111 Knicks, Finals Game 3', 'espn.com', $s2[0], 1);
    $chunks[] = $mk('S2', 'S2-C2', 'https://example.com/espn-game3', 'ESPN — Spurs 115-111 Knicks, Finals Game 3', 'espn.com', $s2[1], 2);
    $chunks[] = $mk('S3', 'S3-C1', 'https://example.com/analytics', 'Finals Analytics Breakdown', 'analytics.example', $s3[0], 1);
    $chunks[] = $mk('S3', 'S3-C2', 'https://example.com/analytics', 'Finals Analytics Breakdown', 'analytics.example', $s3[1], 2);

    return $chunks;
}

$agent = new CountingAgent();
$condenser = new SourceCondenser($agent);
$query = 'Who won the NBA Finals?';

echo "SOURCECONDENSER COST + SPARSITY PROBE (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
echo 'query: ' . $query . "\n\n";

$t0 = microtime(true);
$ledger = $condenser->condense(fixtures(), $query);
$totalMs = (int) round((microtime(true) - $t0) * 1000);

echo 'Gate 2 — call multiplicity + cost' . "\n";
echo '  sources in fixtures: 3' . "\n";
echo '  LLM calls made:      ' . $agent->calls . "\n";
echo '  sequential:          yes (foreach per source, no parallelization)' . "\n";
echo '  temperature:         ' . max(0.1, (float) Config::get('AGENT_CONDENSER_TEMP', 0.2)) . "\n";
echo '  reasoning captured:  no (chat(stream=false) discards reasoning_content; still generated server-side)' . "\n";
echo '  total wall-clock:    ' . $totalMs . " ms\n\n";

foreach ($agent->callLog as $i => $log) {
    echo "  call #" . ($i + 1) . ":\n";
    echo '    in_tokens_est:     ' . $log['in_tokens_est'] . "\n";
    echo '    prompt_tokens:     ' . var_export($log['prompt_tokens'], true) . "\n";
    echo '    completion_tokens: ' . var_export($log['completion_tokens'], true) . "\n";
    echo '    total_tokens:      ' . var_export($log['total_tokens'], true) . "\n";
    echo '    elapsed_ms:        ' . $log['elapsed_ms'] . "\n";
    echo '    out_chars:         ' . $log['out_chars'] . "\n\n";
}

echo "Gate 3 — sparsity (ledger returned for query '{$query}')\n";
$totalClaims = 0;
foreach ($ledger as $entry) {
    echo '  source ' . $entry['sourceId'] . ': ' . count($entry['items']) . " claims\n";
    foreach ($entry['items'] as $item) {
        $totalClaims++;
        echo '    [' . implode(',', $item['chunkIds']) . '] ' . $item['claim'] . "\n";
    }
}
echo '  TOTAL claims across all sources: ' . $totalClaims . "\n";
echo "\nSPARSITY NOTE: the fixtures contain ~" . count(fixtures()) . " chunks of mixed\n";
echo "answer-facts + heavy stat/play-by-play/standings noise. A query-aware condenser\n";
echo "should return only the few answer facts (who won / 4-1 / Game 5), NOT every\n";
echo "stat row. A large claim count means it is atomizing the page, not filtering.\n";
