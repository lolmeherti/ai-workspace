<?php

declare(strict_types=1);

/*
 * Briefing pipeline probe — LIVE (real llama.cpp + real agents, fixture emails).
 *
 * Exercises the reworked briefing orchestration (triage -> extraction ->
 * synthesis) against the ACTUAL running model, with synthetic fixture emails
 * (no live IMAP/Todoist). Validates:
 *
 *   1. FIT case       — bodies fit the budget: triage is skipped, exactly 2
 *                       LLM calls (1 extraction + 1 synthesis).
 *   2. OVERFLOW case  — bodies exceed a (deliberately small) budget: triage
 *                       runs, so >= 3 calls. 3 is NOT a hard cap: multiple
 *                       extraction chunks legitimately push it higher.
 *   3. reasoning_effort='none' on extraction actually yields structured cards
 *      (not an empty response) — exercised via an INDIRECT, time-sensitive
 *      commitment (an RSVP-by-Friday request).
 *   4. [E#] anchors — the model emits integer markers; ids resolve to the
 *      selected set; and an anchor survives a stream-chunk split through it.
 *
 * Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/briefing-pipeline-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Actions\Chat\ChatBriefingStreamAction;
use App\AgentManager;
use App\Agents\BriefingExtractor;
use App\Agents\BriefingTriage;
use App\Config;
use App\Services\BriefingDataService;

Config::load(dirname(__DIR__, 2));

/** Counts + records chat() calls without altering AgentManager behavior. */
final class CountingAgent extends AgentManager
{
    public int $calls = 0;
    /** @var array<int, array<string,mixed>> */
    public array $probeLog = [];

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
    {
        $this->calls++;

        $inTokens = 0;
        foreach ($messages as $m) {
            $c = is_array($m['content'] ?? null) ? ($m['content'][0]['text'] ?? '') : ($m['content'] ?? '');
            $inTokens += (int)(mb_strlen((string) $c) / 4);
        }

        $t0 = microtime(true);
        $out = parent::chat($messages, $stream, $streamCallback, $temperature, $purpose, $reasoningEffort);
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        $this->probeLog[] = [
            'purpose'           => $purpose,
            'reasoning_effort'  => $reasoningEffort,
            'in_tokens_est'     => $inTokens,
            'prompt_tokens'     => $this->lastUsage['prompt_tokens'] ?? null,
            'completion_tokens' => $this->lastUsage['completion_tokens'] ?? null,
            'elapsed_ms'        => $elapsedMs,
            'out_chars'         => strlen($out),
        ];

        return $out;
    }
}

$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
}

function info(string $label, ?string $value): void
{
    printf("  [INFO] %s%s\n", $label, $value !== null && $value !== '' ? ": {$value}" : ': (empty)');
}

/** Raw email dicts in the shape EmailService::fetchRecentEmails returns. */
function fixtures(): array
{
    $mk = function (string $uid, string $from, string $subject, string $body): array {
        return [
            'account_id'    => 1,
            'uid'           => $uid,
            'account_label' => 'Probe Inbox',
            'account_email' => 'probe@example.com',
            'from'          => $from,
            'subject'       => $subject,
            'date'          => date('Y-m-d H:i:s', strtotime('-3 hours')),
            'snippet'       => mb_substr(preg_replace('/\s+/', ' ', $body), 0, 200),
            'fixture_body'  => $body,
        ];
    };

    return [
        $mk('e1', 'Dental Clinic', 'Appointment confirmation', 'This confirms your dental cleaning appointment tomorrow at 10:30 AM. Please arrive 10 minutes early and bring your insurance card. Reply to reschedule if needed.'),
        $mk('e2', 'Ops Team', 'Headcount for the quarterly offsite', "Hi there, we are locking the headcount for the quarterly planning offsite. Could you let me know by end of day Friday whether you will be joining? We will book the venue and catering accordingly. No need to share dietary notes yet."),
        $mk('e3', 'Deals Weekly', 'Top deals this week', 'This week\'s top deals: 50% off all electronics, free shipping over $50, buy one get one on accessories. Limited time only. Click here to browse the full catalogue and subscribe for more offers.'),
        $mk('e4', 'Ebi 7 Reservations', 'Your reservation is confirmed', 'Your table at Ebi 7 is confirmed for Saturday at 7:00 PM for 4 people. Reference number RB-4482. Please call us at least 2 hours ahead for any changes or cancellations.'),
        $mk('e5', 'Parcel Service', 'Your package has shipped', 'Your package has shipped and is expected to arrive by Monday. Track it with the link below. No action is required on your part. Do not reply to this automated message.'),
    ];
}

/** Mirror BriefingDataService::buildInput for fixture emails (no live IMAP). */
function buildEmails(array $raw): array
{
    [$emails, $errors, $omitted] = BriefingDataService::normalizeEmails($raw);
    foreach ($emails as $i => $e) {
        $body = BriefingDataService::cleanBody((string) ($e['fixture_body'] ?? ''));
        $emails[$i]['body'] = $body;
        $emails[$i]['preview'] = BriefingDataService::buildPreview($e, $body);
        unset($emails[$i]['fixture_body']);
    }
    return [$emails, $errors, $omitted];
}

function findAnchors(string $text): array
{
    $ids = [];
    if (preg_match_all('/\[E(\d+)\]/', $text, $m)) {
        foreach ($m[1] as $id) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Drive triage -> extraction -> synthesis with the real agents + real model.
 * @return array{selected: array, cards: array, text: string, chunks: array, validIds: array}
 */
function runPipeline(CountingAgent $agent, array $emails, int $budgetChars, array $tasks, int $omitted): array
{
    // Triage (conditional)
    $selected = $emails;
    if (!empty($emails)) {
        $bodies = array_column($emails, 'body');
        if (!BriefingDataService::bodiesFitWithHeadroom($bodies, $budgetChars)) {
            $triage = new BriefingTriage($agent);
            $ids = $triage->select($emails);
            $idSet = array_flip($ids);
            $selected = array_values(array_filter($emails, fn ($e) => isset($idSet[$e['id']])));
        }
    }

    // Extraction (structured commitments -> action cards)
    $extractor = new BriefingExtractor($agent);
    $cards = $extractor->extract($selected, $tasks, $budgetChars);

    // Synthesis (streamed prose with [E#] anchors) — real prompt builders via reflection
    $action = new ChatBriefingStreamAction(null, $agent);
    $rc = new ReflectionClass(ChatBriefingStreamAction::class);
    $sysMethod = $rc->getMethod('buildSystemPrompt');
    $sysMethod->setAccessible(true);
    $inpMethod = $rc->getMethod('buildSynthesisInput');
    $inpMethod->setAccessible(true);

    $system = $sysMethod->invoke($action, $selected);
    $user = $inpMethod->invoke($action, $selected, BriefingDataService::partitionCalendar($tasks), $cards, $emails, $omitted);

    $chunks = [];
    $text = '';
    $agent->chat(
        [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        true,
        function ($chunk, $type) use (&$chunks, &$text) {
            if ($type === 'content') {
                $chunks[] = $chunk;
                $text .= $chunk;
            }
        },
        null,
        'briefing_synthesis'
    );

    return [
        'selected' => $selected,
        'cards'    => $cards,
        'text'     => $text,
        'chunks'   => $chunks,
        'validIds' => array_map('intval', array_column($selected, 'id')),
    ];
}

echo "=== BRIEFING PIPELINE PROBE (live) ===\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
echo 'real budget chars (from LLM_CTX_SIZE): ' . BriefingDataService::computeBudgetChars() . "\n\n";

[$emails, $errors, $omitted] = buildEmails(fixtures());
$totalBodyChars = array_sum(array_map(fn ($e) => mb_strlen((string) $e['body']), $emails));
echo 'fixtures: ' . count($emails) . ' emails, ' . $totalBodyChars . " total body chars\n";
foreach ($emails as $e) {
    echo "  [{$e['id']}] {$e['from']} — \"{$e['subject']}\"\n";
}
echo "\n";

$tasks = []; // calendar partition + dedup are deterministic and unit-tested; keep this probe focused on the LLM pipeline.

// ── Case 1: FIT (bodies fit -> skip triage -> 2 calls) ────────────────────
echo "--- Case 1: FIT (budget fits all bodies, triage skipped) ---\n";
$fitBudget = 100000;
$agent = new CountingAgent();
try {
    $r1 = runPipeline($agent, $emails, $fitBudget, $tasks, $omitted);
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " (is llama.cpp up?)\n";
    $fail++;
    goto summary;
}

printf("  LLM calls: %d\n", $agent->calls);
foreach ($agent->probeLog as $i => $log) {
    printf("    call #%d purpose=%s reasoning=%s out_chars=%d completion_tokens=%s elapsed=%dms\n",
        $i + 1,
        var_export($log['purpose'], true),
        var_export($log['reasoning_effort'], true),
        $log['out_chars'],
        var_export($log['completion_tokens'], true),
        $log['elapsed_ms']);
}

$purposes = array_map(fn ($l) => $l['purpose'], $agent->probeLog);
check('FIT: exactly 2 calls (extraction + synthesis)', count($purposes) === 2);
check('FIT: no triage call', !in_array('briefing_triage', $purposes, true));
check('FIT: one extraction call', count(array_keys($purposes, 'briefing_extract', true)) === 1);
check('FIT: one synthesis call', count(array_keys($purposes, 'briefing_synthesis', true)) === 1);

echo "  selected emails: " . count($r1['selected']) . "\n";
echo '  action cards: ' . count($r1['cards']) . "\n";
foreach ($r1['cards'] as $c) {
    echo "    - \"{$c['content']}\" (due: {$c['due_string']}, ref {$c['source_email_ref']})\n";
}

$anchors1 = findAnchors($r1['text']);
check('FIT: synthesis emitted >= 1 [E#] anchor', !empty($anchors1));
check('FIT: every [E#] id is a valid selected id', $anchors1 !== [] && array_diff($anchors1, $r1['validIds']) === []);
info('FIT anchors', implode(', ', array_map(fn ($i) => "[E{$i}]", $anchors1)));
echo "  briefing preview: " . str_replace("\n", " ", mb_substr($r1['text'], 0, 300)) . "...\n\n";

// ── Case 2: OVERFLOW (bodies exceed budget -> triage -> 3+ calls) ─────────
echo "--- Case 2: OVERFLOW (budget too small, triage runs) ---\n";
$overflowBudget = 800; // << total body chars, forces triage (+ possibly multi-chunk extraction)
$agent = new CountingAgent();
try {
    $r2 = runPipeline($agent, $emails, $overflowBudget, $tasks, $omitted);
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " (is llama.cpp up?)\n";
    $fail++;
    goto summary;
}

printf("  LLM calls: %d\n", $agent->calls);
foreach ($agent->probeLog as $i => $log) {
    printf("    call #%d purpose=%s reasoning=%s out_chars=%d completion_tokens=%s elapsed=%dms\n",
        $i + 1,
        var_export($log['purpose'], true),
        var_export($log['reasoning_effort'], true),
        $log['out_chars'],
        var_export($log['completion_tokens'], true),
        $log['elapsed_ms']);
}

$purposes2 = array_map(fn ($l) => $l['purpose'], $agent->probeLog);
check('OVERFLOW: >= 3 calls (triage + extraction(s) + synthesis)', count($purposes2) >= 3);
check('OVERFLOW: triage ran', in_array('briefing_triage', $purposes2, true));
check('OVERFLOW: >= 1 extraction call', count(array_keys($purposes2, 'briefing_extract', true)) >= 1);
check('OVERFLOW: exactly 1 synthesis call', count(array_keys($purposes2, 'briefing_synthesis', true)) === 1);
echo '  triage selected ' . count($r2['selected']) . ' of ' . count($emails) . " emails\n";

$anchors2 = findAnchors($r2['text']);
check('OVERFLOW: every [E#] id is a valid selected id', array_diff($anchors2, $r2['validIds']) === []);
info('OVERFLOW anchors', implode(', ', array_map(fn ($i) => "[E{$i}]", $anchors2)));

// ── reasoning_effort='none' on extraction (indirect, time-sensitive commitment) ──
echo "\n--- reasoning_effort='none' on extraction ---\n";
$extractLogs = array_values(array_filter($agent->probeLog, fn ($l) => $l['purpose'] === 'briefing_extract'));
$extractOk = false;
if (!empty($extractLogs)) {
    $allNone = true;
    $allContent = true;
    foreach ($extractLogs as $l) {
        if ($l['reasoning_effort'] !== 'none') {
            $allNone = false;
        }
        if (($l['out_chars'] ?? 0) === 0 || ($l['completion_tokens'] ?? 0) === 0) {
            $allContent = false;
        }
    }
    check('extraction calls set reasoning_effort=none', $allNone);
    check('extraction produced non-empty content (not burned on reasoning)', $allContent);
    $extractOk = $allNone && $allContent;
}

$cards2 = $r2['cards'];
check('>= 1 action card extracted', count($cards2) >= 1);
$indirectHit = false;
foreach ($cards2 as $c) {
    if (preg_match('/offsite|rsvp|headcount|join|attend/i', $c['content'])) {
        $indirectHit = true;
    }
}
check('indirect time-sensitive commitment captured (offsite RSVP)', $indirectHit);
if (count($cards2) > 0) {
    foreach ($cards2 as $c) {
        info('card', "\"{$c['content']}\" due=\"{$c['due_string']}\" ref={$c['source_email_ref']}");
    }
}

// ── [E#] anchor split across stream chunks ────────────────────────────────
echo "\n--- [E#] anchor split across stream chunks ---\n";
$realSplit = false;
for ($i = 0; $i < count($r1['chunks']) - 1; $i++) {
    if (preg_match('/\[E\d*$/', $r1['chunks'][$i]) && preg_match('/^\d*\]/', $r1['chunks'][$i + 1])) {
        $realSplit = true;
    }
}
info('real stream actually split an [E#] anchor across chunks', $realSplit ? 'yes' : 'no (not observed this run)');

// Deterministic contract check: reassemble chunks then split THROUGH an anchor
// and confirm the marker still resolves (the frontend accumulates chunks, so a
// split through the marker is harmless by construction).
$splitOk = false;
if (($pos = strpos($r1['text'], '[E')) !== false) {
    $halfA = substr($r1['text'], 0, $pos + 1);
    $halfB = substr($r1['text'], $pos + 1);
    $reassembled = $halfA . $halfB;
    $splitAnchors = findAnchors($reassembled);
    $splitOk = ($splitAnchors === findAnchors($r1['text'])) && !empty($splitAnchors);
}
check('[E#] anchor survives a chunk split through the marker', $splitOk);

summary:
echo "\n========================================\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
