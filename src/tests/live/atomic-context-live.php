<?php

declare(strict_types=1);

/*
 * Phase 4 — live verification of production SourceCondenser::condenseBatched()
 * + compression measurement on the NBA fixture. ONE LLM call.
 *
 * Confirms the real (non-probe) batched method extracts sparse query-relevant
 * atoms with valid single-source provenance, and records raw-backing vs atomic
 * HOT token reduction.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomic-context-live.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\SourceCondenser;
use App\Search\WebChunk;

Config::load(dirname(__DIR__, 2));

final class CountingAgent extends AgentManager
{
    public int $calls = 0;
    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
    {
        $this->calls++;
        return parent::chat($messages, $stream, $streamCallback, $temperature);
    }
}

function mk(string $src, string $cid, string $title, string $domain, string $text, int $pos): WebChunk
{
    $url = 'https://example.com/' . strtolower(str_replace(' ', '-', $src));
    return new WebChunk(
        $src, $cid, $url, $url, $title, $domain,
        null, null, '2026-06-15T00:00:00Z', [], 'entity',
        $text, $pos, null, null, null, null
    );
}

$chunks = [
    mk('S1', 'S1-C1', 'Basketball-Reference Finals', 'basketball-reference.com',
        'The 2026 NBA Finals was won by the New York Knicks, who defeated the '
        . 'San Antonio Spurs four games to one. The series concluded on June 14, '
        . '2026 with a Game 5 victory by the Knicks, 112-104.', 1),
    mk('S1', 'S1-C2', 'Basketball-Reference Finals', 'basketball-reference.com',
        'Finals MVP Jalen Brunson averaged 28.4 points, 7.2 assists and 5.1 rebounds.', 2),
    mk('S1', 'S1-C3', 'Basketball-Reference Finals', 'basketball-reference.com',
        'Player stats: Karl-Anthony Towns 24.1 pts 9.8 reb; Josh Hart 9.2 pts 11.1 reb. '
        . 'Advanced metrics: PER 28.4, BPM +6.2, TS% .612.', 3),
    mk('S1', 'S1-C4', 'Basketball-Reference Finals', 'basketball-reference.com',
        'Regular season: Knicks 54-28 (1st East), Spurs 51-31 (1st West).', 4),
    mk('S2', 'S2-C1', 'ESPN Game 3 recap', 'espn.com',
        'SAN ANTONIO — The Spurs beat the Knicks 115-111 in Game 3 of the Finals '
        . 'to cut the series deficit to 2-1. Wembanyama scored 38 points.', 1),
    mk('S2', 'S2-C2', 'ESPN Game 3 recap', 'espn.com',
        'Play by play: Q1 12:00 jump ball won by Spurs; Q1 11:45 Wembanyama blocks '
        . 'Brunson; Q1 11:20 Knicks turnover.', 2),
    mk('S3', 'S3-C1', 'Finals Analytics', 'analytics.example',
        'The Knicks posted a net rating of +8.4 during the Finals.', 1),
    mk('S3', 'S3-C2', 'Finals Analytics', 'analytics.example',
        'Injury report: no players listed; Tre Jones probable for Game 4.', 2),
];

$agent = new CountingAgent();
$condenser = new SourceCondenser($agent);
$query = 'Who won the NBA Finals?';

$t0 = microtime(true);
$claims = $condenser->condenseBatched($chunks, $query);
$ms = (int) round((microtime(true) - $t0) * 1000);

$backingText = implode("\n", array_map(fn($c) => $c->text, $chunks));
$hotText = implode("\n", array_map(fn($c) => "[{$c['source_id']}] {$c['claim']}", $claims));
$backingTokens = (int) (mb_strlen($backingText) / 4);
$hotTokens = (int) (mb_strlen($hotText) / 4);
$ratio = $backingTokens > 0 ? round($hotTokens / $backingTokens, 4) : 0;

echo "PHASE 4 LIVE — production condenseBatched (NBA fixture)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
echo 'query: ' . $query . "\n\n";
echo "  LLM calls:        {$agent->calls} (expect 1)\n";
echo "  latency:          {$ms} ms\n";
echo "  raw backing:      {$backingTokens} tokens (char/4), " . count($chunks) . " chunks\n";
echo "  atomic HOT:       {$hotTokens} tokens (char/4), " . count($claims) . " claims\n";
echo "  compression:      {$ratio}x of raw backing\n";
if ($agent->lastUsage) {
    echo '  server usage:     prompt=' . ($agent->lastUsage['prompt_tokens'] ?? '?')
        . ' completion=' . ($agent->lastUsage['completion_tokens'] ?? '?') . "\n";
}
echo "\n  atoms:\n";
foreach ($claims as $c) {
    echo '    [' . $c['source_id'] . '] ' . $c['claim'] . "\n";
}
