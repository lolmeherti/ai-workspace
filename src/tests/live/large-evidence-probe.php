<?php

declare(strict_types=1);

/*
 * LARGE-EVIDENCE BREAK-EVEN PROBE (live).
 *
 * The small-case probe (next-turn-cost-probe.php) showed that for ~125 raw
 * evidence tokens, atomization is a net LOSS: the ~4s consolidation call
 * dwarfs the prompt savings, and shrinking ~101 prompt tokens is meaningless.
 *
 * This probe maps the BREAK-EVEN CURVE: it seeds controlled multi-source web
 * evidence at ~500 / ~2k / ~5k / ~8k raw tokens, runs the REAL production
 * atomization path (SourceCondenser::condenseBatched, one LLM call), then
 * measures what the NEXT turn costs with atoms injected vs with the raw
 * evidence kept. For each bracket it records:
 *
 *   - raw evidence tokens (TokenCounter via llama /tokenize)
 *   - atom tokens + atomization compression ratio
 *   - total next-turn prompt tokens, raw vs atoms (server-reported usage)
 *   - raw warm TTFT, raw cold TTFT, atoms cold TTFT (prefill proxy)
 *   - consolidation duration (the atomization LLM call)
 *
 * and computes the number of future turns at which the saved prefill cost +
 * reduced context growth justifies the one-time consolidation call and cache
 * loss.
 *
 * Assumption (mirrors production): the data_fetching row's `message` is the
 * full raw evidence text and `atomic_context` is the atom JSON. On the next
 * turn atoms render as compact "[S#] fact" lines; the raw config forces the
 * full message via richRowIds.
 *
 * Requires llama.cpp running (launcher boot). Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/large-evidence-probe.php [query] [brackets]
 *
 * brackets = comma list of raw-token targets (default 500,2000,5000,8000).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Database;
use App\Search\SourceCondenser;
use App\Search\TokenCounter;
use App\Search\WebChunk;
use App\Services\PromptAssemblyService;

/**
 * AgentManager subclass that records the last (thought-stripped) response the
 * model produced — i.e. exactly the string SourceCondenser::parseClaimsBatched
 * receives — so the probe can diagnose WHY N completion tokens yield only K
 * claims.
 */
final class CapturingAgent extends AgentManager
{
    public ?string $lastRaw = null;

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): string
    {
        $out = parent::chat($messages, $stream, $streamCallback, $temperature);
        $this->lastRaw = $out;
        return $out;
    }
}

Config::load(dirname(__DIR__, 2));

// Deterministic overrides — don't let .env drift change the measurement.
$_ENV['CHAT_ROLLING_WINDOW_LIMIT'] = '15';
$_ENV['LLM_EVIDENCE_TOOL_ROLE'] = '0';

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new CapturingAgent();
$pas = new PromptAssemblyService($db, '/tmp');
$counter = new TokenCounter();
$condenser = new SourceCondenser($agent);

$query = $argv[1] ?? 'Who won the 2026 NBA Finals and what was the final score?';
$followUp = 'Thanks — give me a one-sentence summary.';

$bracketsArg = $argv[2] ?? '500,2000,5000,8000';
$brackets = array_map('intval', array_filter(array_map('trim', explode(',', $bracketsArg))));

echo "LARGE-EVIDENCE BREAK-EVEN PROBE (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . "\n";
echo "query: {$query}\n";
echo 'follow-up: "' . $followUp . "\"\n";
echo 'brackets: ' . implode(', ', $brackets) . " raw-token targets\n\n";

// =====================================================================
// Fixtures — realistic multi-source web evidence with sparse answer facts
// =====================================================================

/** Query-relevant seed facts, keyed by source. S1 is the core answer. */
function seedFacts(): array
{
    return [
        'S1' => [
            'The 2026 NBA Finals was won by the New York Knicks.',
            'The Knicks defeated the San Antonio Spurs four games to one.',
            'The series concluded on June 14, 2026 with a Game 5 victory by the Knicks.',
            'The Knicks won the clinching Game 5 by a final score of 112-104.',
            'Jalen Brunson was named the 2026 Finals MVP.',
        ],
        'S2' => [
            'The Spurs won Game 3 in San Antonio by a score of 115-111 to cut the series deficit to 2-1.',
            'Victor Wembanyama scored 38 points with 14 rebounds and 6 blocks in Game 3.',
        ],
        'S3' => [
            'The Knicks clinched the championship at home in Madison Square Garden.',
            'This was the Knicks first NBA title since 1973.',
        ],
        'S4' => [
            'Brunson averaged 28.4 points, 7.2 assists and 5.1 rebounds across the five Finals games.',
            'The Knicks won Games 1, 2, 4 and 5 while the Spurs won only Game 3.',
        ],
        'S5' => [
            'The Knicks championship parade was held on June 18, 2026 in Manhattan.',
            'This was the first Knicks championship of the twenty-first century.',
        ],
    ];
}

/** Query-irrelevant / tangential noise, cycled to reach target token counts. */
function fillerPool(): array
{
    return [
        'In the regular season the Knicks finished 54-28 to claim the top seed in the Eastern Conference while the Spurs went 51-31 to win the West.',
        'The Knicks playoff run included a 4-2 second-round win over the Boston Celtics and a 4-1 conference finals victory over the Cleveland Cavaliers.',
        'The Spurs reached the Finals by beating the Oklahoma City Thunder 4-3 and the Minnesota Timberwolves 4-2.',
        'New York led the league in defensive rating at 108.1 points allowed per 100 possessions during the regular season.',
        'San Antonio posted a league-best 118.7 offensive rating behind Wembanyama interior scoring.',
        'Karl-Anthony Towns averaged 24.1 points and 9.8 rebounds while shooting 41.2 percent from three-point range in the series.',
        'OG Anunoby contributed 12.3 points and 4.0 rebounds per game with strong perimeter defense on San Antonio wings.',
        'Josh Hart recorded 9.2 points, 11.1 rebounds and 6.4 assists per game and logged the most minutes of any Knicks rotation player.',
        'Jalen Brunson Finals true shooting percentage of 61.2 percent led all players with at least 100 minutes.',
        'Wembanyama Player Efficiency Rating of 28.4 ranked first among all players in the 2026 postseason.',
        'Advanced metrics showed Brunson Box Plus/Minus at +6.2 and his Value Over Replacement Player at 1.9 during the Finals.',
        'The Knicks bench outscored the Spurs reserves by an average of 6.5 points per game across the series.',
        'Game 1 opened with a 9-2 Knicks run as Brunson hit back-to-back step-back three-pointers in the first three minutes.',
        'In Game 4 the Knicks erased a 15-point third-quarter deficit with a 22-4 run spanning the final six minutes of the period.',
        'The clinching Game 5 featured 14 lead changes and seven ties before New York pulled away in the fourth quarter.',
        'New York listed no players on the Finals injury report for the duration of the series.',
        'San Antonio guard Tre Jones was listed as probable for Game 4 with a right ankle sprain and played 21 minutes.',
        'Knicks forward Mitchell Robinson returned from a knee procedure in the conference semifinals and averaged 8.4 minutes off the bench.',
        'The Finals averaged 18.4 million viewers per game, the highest mark for the league since the 2019 series.',
        'Average ticket prices on the resale market for the Finals reached 1950 dollars, up 22 percent from the previous year.',
        'Madison Square Garden sold out all three home games with a listed capacity crowd of 19812.',
        'The Knicks championship merchandise sales broke the league single-day record on June 15.',
        'Knicks head coach Tom Thibodeau won his first championship and was praised for his second-half defensive adjustments.',
        'Spurs head coach Gregg Popovich credited the Knicks perimeter switching for disrupting San Antonio pick-and-roll.',
        'The Knicks front office signed three undrafted free agents in the summer of 2025 who all contributed rotation minutes in the Finals.',
        'The series followed a 2-2-1-1-1 format with Games 1 and 2 in New York and Game 3 in San Antonio.',
        'Games 1 through 4 were scheduled on alternating nights before a two-day travel break ahead of Game 5.',
        'The championship parade route ran from Battery Park to City Hall and drew an estimated one million spectators.',
        'The Knicks entered the Finals as two-point favorites for Game 1 after opening as plus-150 underdogs at the start of the postseason.',
        'Sportsbooks reported 61 percent of the money on the Knicks to win the series outright.',
        'Brunson 42-point performance in Game 2 set a new Knicks franchise record for points in a Finals game.',
        'The Knicks became the first team since 1994 to win a Finals game by 30 or more points, taking Game 2 by a 126-96 margin.',
        'Wembanyama blocked 16 shots over the first three games, the most by any player through three Finals games since 2003.',
        'The series marked the first Finals meeting between the Knicks and Spurs since the 1999 championship.',
        'San Antonio DeAaron Fox averaged 21.3 points and 7.1 assists as the Spurs primary ball-handler in the series.',
        'The Knicks shot 38.9 percent from three-point range as a team, up from 34.7 percent in the regular season.',
        'New York zone defense held San Antonio to 42.1 percent shooting in the half-court over the final two games.',
        'A record 14 different players scored in double figures at least once during the five-game series.',
        'The league announced the 2026-27 salary cap projection of 154 million dollars during the Finals media week.',
        'Brunson was named Eastern Conference Player of the Month for June, edging out Boston Jayson Tatum.',
        'The Spurs rookie class logged the fewest total playoff minutes of any Finals team in the last decade.',
        'Game 5 national anthem was performed by a New York Philharmonic string quartet.',
        'Analysts noted the Knicks offensive rebound rate of 31.4 percent was the highest in any Finals since tracking began.',
        'The Knicks five-game series win came after falling behind 2-1 in each of their first two playoff series.',
        'The Spurs signed a pair of veteran free agents in July 2025 to add shooting depth around Wembanyama.',
        'A league survey of general managers named the Knicks the most likely 2027 champions at 42 percent of votes.',
    ];
}

function mkChunk(string $src, string $cid, array $spec, string $text, int $pos): WebChunk
{
    $url = 'https://example.com/' . strtolower(str_replace(' ', '-', $src)) . '/' . strtolower($cid);
    return new WebChunk(
        $src, $cid, $url, $url, $spec['title'], $spec['domain'],
        null, null, '2026-06-15T00:00:00Z', [], 'entity',
        $text, $pos, null, null, null, null
    );
}

/**
 * Build synthetic multi-source evidence sized to ~$targetTokens raw tokens.
 * Source S1 carries the core answer facts; sources 2..N carry one secondary
 * fact each; the rest of every chunk is query-irrelevant noise.
 *
 * @return WebChunk[]
 */
function buildEvidence(int $targetTokens): array
{
    $specs = [
        'S1' => ['title' => 'Basketball-Reference — 2026 NBA Finals', 'domain' => 'basketball-reference.com'],
        'S2' => ['title' => 'ESPN — Finals Game 3 recap', 'domain' => 'espn.com'],
        'S3' => ['title' => 'NBA.com — Finals coverage', 'domain' => 'nba.com'],
        'S4' => ['title' => 'The Athletic — Finals analysis', 'domain' => 'theathletic.com'],
        'S5' => ['title' => 'Yahoo Sports — Finals news', 'domain' => 'sports.yahoo.com'],
    ];
    $facts = seedFacts();

    $numSources = $targetTokens <= 700 ? 2 : ($targetTokens <= 3000 ? 3 : ($targetTokens <= 6000 ? 4 : 5));
    $targetChars = max(500, $targetTokens * 4);

    $sourceIds = array_slice(array_keys($facts), 0, $numSources);
    $factsChars = 0;
    foreach ($sourceIds as $sid) {
        $factsChars += strlen(implode(' ', $facts[$sid]));
    }

    $chunksPerSource = 2;
    $totalChunks = $numSources * $chunksPerSource;
    $fillerBudget = max(0, $targetChars - $factsChars);
    $perChunkFiller = (int) floor($fillerBudget / $totalChunks);

    $pool = fillerPool();
    $poolN = count($pool);
    $cursor = 0;
    $fill = function (int $chars) use (&$cursor, $pool, $poolN): string {
        $out = '';
        while (strlen($out) < $chars) {
            $out .= $pool[$cursor % $poolN] . ' ';
            $cursor++;
        }
        return rtrim($out);
    };

    $chunks = [];
    foreach ($sourceIds as $sid) {
        $spec = $specs[$sid];
        $factsText = implode(' ', $facts[$sid]);
        $chunks[] = mkChunk($sid, "{$sid}-C1", $spec, $factsText . ' ' . $fill($perChunkFiller), 1);
        $chunks[] = mkChunk($sid, "{$sid}-C2", $spec, $fill($perChunkFiller), 2);
    }

    return $chunks;
}

function fmtInt($v): string
{
    return $v === null ? 'n/a' : (string) $v;
}

/**
 * Return a copy of $messages with a unique nonce appended to the system
 * prompt. llama.cpp's prefix cache is shared with a large chat-template
 * preamble, so a bare "flush" call doesn't actually evict; prepending a
 * nonce to the system prompt guarantees the next prompt shares no prefix
 * (cache_n=0) and is therefore a true cold prefill.
 */
function withNonce(array $messages, string $nonce): array
{
    $out = $messages;
    if (isset($out[0]['content']) && is_string($out[0]['content'])) {
        $out[0]['content'] .= "\n[nonce:{$nonce}]";
    }
    return $out;
}

function measure(AgentManager $agent, array $messages): array
{
    $firstMs = null;
    $t0 = microtime(true);
    $agent->chat($messages, true, function (string $chunk, string $type) use (&$firstMs, $t0): void {
        if ($firstMs === null) {
            $firstMs = (int) round((microtime(true) - $t0) * 1000);
        }
    }, 0.0);
    $elapsed = (int) round((microtime(true) - $t0) * 1000);
    $usage = $agent->lastUsage ?? [];
    $timings = $agent->lastTimings ?? [];
    return [
        'ttft_ms' => $firstMs,
        'elapsed_ms' => $elapsed,
        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
        'completion_tokens' => $usage['completion_tokens'] ?? null,
        'prompt_ms' => $timings['prompt_ms'] ?? null,
        'prompt_n' => $timings['prompt_n'] ?? null,
        'cache_n' => $timings['cache_n'] ?? null,
        'predicted_ms' => $timings['predicted_ms'] ?? null,
        'predicted_n' => $timings['predicted_n'] ?? null,
    ];
}

// =====================================================================
// Per-bracket measurement
// =====================================================================

$results = [];

foreach ($brackets as $targetTokens) {
    echo str_repeat('=', 72) . "\n";
    echo "BRACKET ~{$targetTokens} raw evidence tokens\n";
    echo str_repeat('=', 72) . "\n";

    // 1. Build controlled evidence.
    $chunks = buildEvidence($targetTokens);
    $rawText = implode("\n\n", array_map(fn($c) => $c->text, $chunks));

    // 2. Raw vs atom token counts.
    $rawTokens = $counter->count($rawText);
    $rawChars4 = (int) (strlen($rawText) / 4);
    echo "  sources: " . count(array_unique(array_map(fn($c) => $c->sourceId, $chunks)))
        . ", chunks: " . count($chunks) . "\n";
    echo "  raw evidence tokens : {$rawTokens} (char/4={$rawChars4})\n";

    // 3. Consolidation (the atomization LLM call).
    $t0 = microtime(true);
    try {
        $claims = $condenser->condenseBatched($chunks, $query);
        $consolidationMs = (int) round((microtime(true) - $t0) * 1000);
        $consUsage = $agent->lastUsage ?? [];
    } catch (\Throwable $e) {
        echo "  CONSOLIDATION ERROR: " . $e->getMessage() . "\n";
        $consolidationMs = -1;
        $claims = [];
        $consUsage = [];
    }
    $condRaw = $agent->lastRaw ?? '';

    echo "  atoms (" . count($claims) . " claims):\n";
    foreach ($claims as $c) {
        echo "    [{$c['source_id']}] {$c['claim']}\n";
    }
    $rawPreview = mb_substr($condRaw, 0, 900);
    if (mb_strlen($condRaw) > 900) {
        $rawPreview .= '...[truncated]';
    }
    echo "  condenser raw output (" . strlen($condRaw) . " chars): "
        . str_replace("\n", "\n    ", $rawPreview) . "\n";

    $atomLines = implode("\n", array_map(fn($c) => "[{$c['source_id']}] {$c['claim']}", $claims));
    $atomTokens = $counter->count($atomLines);
    $atomChars4 = (int) (strlen($atomLines) / 4);
    $ratio = $atomTokens > 0 ? round($rawTokens / $atomTokens, 2) : null;

    echo "  consolidation       : {$consolidationMs} ms"
        . " (in=" . fmtInt($consUsage['prompt_tokens'] ?? null)
        . " out=" . fmtInt($consUsage['completion_tokens'] ?? null) . ")\n";
    echo "  atom tokens         : {$atomTokens} (char/4={$atomChars4}), " . count($claims) . " claims\n";
    echo "  compression ratio   : " . ($ratio === null ? 'n/a' : "{$ratio}x") . "\n";

    // 4. Seed a data_fetching row and build next-turn messages in both configs.
    $db->insert('chat_sessions', ['title' => 'large-evidence-probe', 'context_tokens' => 0]);
    $sessionId = (int) $db->getConnection()->lastInsertId();

    $sourceMap = [];
    foreach ($chunks as $c) {
        $sourceMap[$c->sourceId] = ['url' => $c->url, 'title' => $c->title];
    }

    $db->insert('chat_history', [
        'session_id' => $sessionId,
        'role' => 'system',
        'message' => $rawText,
        'message_type' => 'data_fetching',
        'active_context' => 1,
        'atomic_context' => !empty($claims) ? json_encode($claims, JSON_UNESCAPED_UNICODE) : null,
        'source_map' => json_encode($sourceMap, JSON_UNESCAPED_UNICODE),
    ]);
    $rowId = (int) $db->getConnection()->lastInsertId();

    $systemPrompt = $pas->buildSystemPrompt($followUp);
    $history = $db->selectSafe('chat_history', ['session_id' => $sessionId]);

    $msgsAtoms = $pas->buildMessagesArray($systemPrompt, $history, [], []);
    $msgsAtoms[] = ['role' => 'user', 'content' => $followUp];

    $msgsRaw = $pas->buildMessagesArray($systemPrompt, $history, [], [$rowId]);
    $msgsRaw[] = ['role' => 'user', 'content' => $followUp];

    // 5. Measure next turn. Each cold measurement uses a unique nonce in the
    //    system prompt so it shares no cached prefix (true cold, cache_n=0);
    //    raw warm reuses raw cold's nonce so it stays a full cache hit.
    $atoms = measure($agent, withNonce($msgsAtoms, "atoms-{$targetTokens}-" . bin2hex(random_bytes(4))));
    $rawNonce = "raw-{$targetTokens}-" . bin2hex(random_bytes(4));
    $rawCold = measure($agent, withNonce($msgsRaw, $rawNonce));
    $rawWarm = measure($agent, withNonce($msgsRaw, $rawNonce));

    echo "  next-turn prompt tokens:\n";
    echo "    atoms : " . fmtInt($atoms['prompt_tokens']) . "\n";
    echo "    raw   : " . fmtInt($rawCold['prompt_tokens']) . "\n";
    echo "  server prefill (prompt_ms, cache_n):\n";
    echo "    atoms cold : " . fmtInt($atoms['prompt_ms']) . " ms (cache_n=" . fmtInt($atoms['cache_n']) . ")\n";
    echo "    raw   cold : " . fmtInt($rawCold['prompt_ms']) . " ms (cache_n=" . fmtInt($rawCold['cache_n']) . ")\n";
    echo "    raw   warm : " . fmtInt($rawWarm['prompt_ms']) . " ms (cache_n=" . fmtInt($rawWarm['cache_n']) . ")\n";
    echo "  TTFT (first-chunk wall clock):\n";
    echo "    atoms cold : " . fmtInt($atoms['ttft_ms']) . " ms\n";
    echo "    raw   cold : " . fmtInt($rawCold['ttft_ms']) . " ms\n";
    echo "    raw   warm : " . fmtInt($rawWarm['ttft_ms']) . " ms\n";

    // 6. Comparison / break-even. Server-reported prefill (prompt_ms) is the
    //    authoritative metric; TTFT is kept as a noisy cross-check only.
    $promptSave = ($rawCold['prompt_tokens'] !== null && $atoms['prompt_tokens'] !== null)
        ? $rawCold['prompt_tokens'] - $atoms['prompt_tokens'] : null;
    $prefillSaving = ($rawCold['prompt_ms'] !== null && $atoms['prompt_ms'] !== null)
        ? round($rawCold['prompt_ms'] - $atoms['prompt_ms'], 1) : null;
    $kvValue = ($rawCold['prompt_ms'] !== null && $rawWarm['prompt_ms'] !== null)
        ? round($rawCold['prompt_ms'] - $rawWarm['prompt_ms'], 1) : null;
    $prefillRate = ($rawCold['prompt_ms'] !== null && $rawCold['prompt_n'] > 0)
        ? round($rawCold['prompt_ms'] / $rawCold['prompt_n'], 2) : null;

    echo "  prompt shrink (atoms vs raw)   : " . fmtInt($promptSave) . " tokens\n";
    echo "  prefill saving per future turn : " . fmtInt($prefillSaving) . " ms\n";
    echo "  KV/prefix-reuse value (raw)    : " . fmtInt($kvValue) . " ms\n";
    echo "  prefill rate (raw cold)        : " . ($prefillRate === null ? 'n/a' : $prefillRate . ' ms/token') . "\n";

    $breakEven = null;
    if ($prefillSaving !== null && $prefillSaving > 0 && $consolidationMs > 0) {
        $breakEven = ($consolidationMs + ($kvValue ?? 0)) / $prefillSaving;
        echo "  BREAK-EVEN: " . round($breakEven, 1) . " future turns\n";
    } else {
        echo "  BREAK-EVEN: never (prefill saving <= 0)\n";
    }

    $results[] = [
        'target_tokens' => $targetTokens,
        'raw_evidence_tokens' => $rawTokens,
        'atom_tokens' => $atomTokens,
        'atom_claims' => count($claims),
        'compression_ratio' => $ratio,
        'next_prompt_tokens_raw' => $rawCold['prompt_tokens'],
        'next_prompt_tokens_atoms' => $atoms['prompt_tokens'],
        'prompt_shrink_tokens' => $promptSave,
        'raw_warm_prefill_ms' => $rawWarm['prompt_ms'],
        'raw_cold_prefill_ms' => $rawCold['prompt_ms'],
        'atoms_cold_prefill_ms' => $atoms['prompt_ms'],
        'raw_warm_cache_n' => $rawWarm['cache_n'],
        'raw_cold_cache_n' => $rawCold['cache_n'],
        'atoms_cold_cache_n' => $atoms['cache_n'],
        'raw_warm_ttft_ms' => $rawWarm['ttft_ms'],
        'raw_cold_ttft_ms' => $rawCold['ttft_ms'],
        'atoms_cold_ttft_ms' => $atoms['ttft_ms'],
        'prefill_rate_ms_per_token' => $prefillRate,
        'consolidation_ms' => $consolidationMs,
        'kv_reuse_value_ms' => $kvValue,
        'prefill_saving_ms' => $prefillSaving,
        'break_even_turns' => $breakEven,
    ];

    $db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
    echo "\n";
}

// =====================================================================
// Summary
// =====================================================================

echo str_repeat('=', 72) . "\n";
echo "BREAK-EVEN CURVE (server prefill ms)\n";
echo str_repeat('=', 72) . "\n";
printf("%-6s | %-7s | %-7s | %-6s | %-7s | %-8s | %-8s | %-8s | %-7s | %-8s | %-9s\n",
    'target', 'rawTok', 'atomTok', 'ratio', 'consol', 'rawCold', 'atmCold', 'rawWarm', 'saveMs', 'cacheN', 'breakEven');
foreach ($results as $r) {
    printf("%-6s | %-7s | %-7s | %-6s | %-7s | %-8s | %-8s | %-8s | %-7s | %-8s | %-9s\n",
        $r['target_tokens'],
        $r['raw_evidence_tokens'],
        $r['atom_tokens'],
        $r['compression_ratio'] === null ? 'n/a' : ($r['compression_ratio'] . 'x'),
        fmtInt($r['consolidation_ms']),
        fmtInt($r['raw_cold_prefill_ms']),
        fmtInt($r['atoms_cold_prefill_ms']),
        fmtInt($r['raw_warm_prefill_ms']),
        fmtInt($r['prefill_saving_ms']),
        $r['raw_cold_cache_n'] . '/' . $r['raw_warm_cache_n'],
        $r['break_even_turns'] === null ? 'never' : round($r['break_even_turns'], 1) . ' turns'
    );
}

echo "\nINTERPRETATION:\n";
echo "  - rawCold/atmCold/rawWarm = server-reported prefill time (prompt_ms) with\n";
echo "    the KV cache flushed before each cold measurement (cache_n=0).\n";
echo "  - 'saveMs' = raw cold prefill minus atoms cold prefill (prefill time saved\n";
echo "    per future turn).\n";
echo "  - 'breakEven' = (consolidation_ms + one-time KV loss) / saveMs — the number\n";
echo "    of future turns before atomization pays back its own cost.\n";
echo "  - If saveMs stays tiny even at 8k raw tokens, universal post-answer atomization\n";
echo "    is the wrong optimization: it should become conditional on evidence size /\n";
echo "    context pressure, not run after every web tool call.\n";

$snapshot = sys_get_temp_dir() . '/large_evidence_probe_' . date('Ymd_His') . '.json';
file_put_contents($snapshot, json_encode([
    'model' => (string) Config::get('LLM_MODEL_NAME', '?'),
    'query' => $query,
    'follow_up' => $followUp,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nresults saved: {$snapshot}\n";
echo "\ndone\n";
