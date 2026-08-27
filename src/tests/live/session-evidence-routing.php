<?php

declare(strict_types=1);

/*
 * search_session_evidence routing eval — LIVE, independent of run.php.
 *
 * The other tools are exercised cold (single turn). search_session_evidence is
 * different: it re-reads web evidence already fetched earlier in the SAME
 * conversation, so it can only be triggered from a two-turn flow — turn 1 does
 * a web search (which retains its chunks), turn 2 asks a follow-up whose detail
 * is in the retained chunks but NOT in the compact atom summary.
 *
 * This script plants a realistic, already-atomized web-evidence block (raw
 * evicted, only compact atoms injected) into a throwaway session, then drives
 * the real ChatManager::firstPass on a follow-up and asserts the model routes
 * to search_session_evidence instead of searching the web again. It also runs
 * the tool for real against the fixture and checks it rehydrates the retained
 * chunk. A control case (unrelated follow-up) must still route to search_web.
 *
 * Uses a fictional product ("Aura X9") so the model has no prior knowledge and
 * MUST draw on the retained evidence.
 *
 * Usage:
 *   docker exec ai_php_web php /var/www/html/tests/live/session-evidence-routing.php
 *   docker exec ai_php_web php /var/www/html/tests/live/session-evidence-routing.php --passes 3
 *
 * Exit code 0 when every check passes, else 1.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

Config::load(dirname(__DIR__, 2));

$argv = $argv ?? [];
$passes = 1;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--passes' && isset($argv[$i + 1])) {
        $passes = max(1, (int) $argv[++$i]);
    }
}

$db = new Database();
$db->initTables();

$agent = new AgentManager();
$cm = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, Config::getProjectRoot() . '/uploads/');
$tes = new ToolExecutionService($db, $agent, Config::getProjectRoot() . '/uploads/');

$rc = new ReflectionClass(ChatManager::class);
$firstPass = $rc->getMethod('firstPass');
$firstPass->setAccessible(true);

$pass = 0;
$fail = 0;
$check = function (string $label, bool $cond) use (&$pass, &$fail) {
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
};

echo "SESSION-EVIDENCE ROUTING EVAL (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n";
printf("passes: %d\n", $passes);

// ---------------------------------------------------------------------------
// Fixture: a fictional product + one prior web-search evidence block that has
// already been atomized (raw evicted, only compact atoms injected). The exact
// mAh figure lives ONLY in backing_chunks, not in the atoms — so a follow-up
// for it must re-read the retained evidence.
// ---------------------------------------------------------------------------

const TOPIC_QUERY = 'Aura X9 smartwatch specs';
const FOLLOW_UP = 'What is the exact battery capacity in mAh of the Aura X9?';

function backingChunksJson(): string
{
    $now = date('c');
    $chunks = [
        [
            'sourceId' => 'S1',
            'chunkId' => 'S1-C1',
            'url' => 'https://example.com/aura-x9-specs',
            'finalUrl' => 'https://example.com/aura-x9-specs',
            'title' => 'Aura X9 — Full Specifications',
            'domain' => 'example.com',
            'publishedAt' => null,
            'updatedAt' => null,
            'fetchedAt' => $now,
            'headingPath' => ['Specifications'],
            'sectionType' => 'entity',
            'text' => 'The Aura X9 smartwatch features a 46 mm titanium case, a 1.5 GHz dual-core processor, and a 420 mAh lithium-polymer battery delivering up to 3 days of battery life. It has a 2000-nit always-on AMOLED display, built-in GPS, and 5ATM water resistance.',
            'position' => 1,
            'entityId' => null,
            'entityType' => null,
            'author' => null,
            'score' => null,
        ],
        [
            'sourceId' => 'S1',
            'chunkId' => 'S1-C2',
            'url' => 'https://example.com/aura-x9-specs',
            'finalUrl' => 'https://example.com/aura-x9-specs',
            'title' => 'Aura X9 — Full Specifications',
            'domain' => 'example.com',
            'publishedAt' => null,
            'updatedAt' => null,
            'fetchedAt' => $now,
            'headingPath' => ['Battery'],
            'sectionType' => 'entity',
            'text' => 'Battery: 420 mAh lithium-polymer, charged via magnetic puck, 0-80% in 45 minutes. The 420 mAh cell is the exact rated capacity.',
            'position' => 2,
            'entityId' => null,
            'entityType' => null,
            'author' => null,
            'score' => null,
        ],
    ];
    return json_encode($chunks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Atoms deliberately omit the "420 mAh" figure.
function atomicContextJson(): string
{
    return json_encode([
        ['source_id' => 'S1', 'claim' => 'Aura X9 has a 46 mm titanium case and 3-day battery life'],
        ['source_id' => 'S1', 'claim' => '2000-nit AMOLED display, built-in GPS, 5ATM water resistance'],
    ], JSON_UNESCAPED_UNICODE);
}

function rawMessage(): string
{
    return "<source id=\"S1\">Aura X9 — Full Specifications</source>\n"
        . "The Aura X9 smartwatch features a 46 mm titanium case, a 1.5 GHz dual-core processor, and a 420 mAh lithium-polymer battery delivering up to 3 days of battery life.";
}

/**
 * Create a throwaway session that already contains a completed web search on
 * the topic (turn 1 user -> data_fetching -> assistant). Returns the session id.
 */
function makeSession(Database $db): int
{
    $db->insert('chat_sessions', [
        'title' => 'LIVE session-evidence test',
    ]);
    $sid = (int) $db->getConnection()->lastInsertId();

    $db->insert('chat_history', [
        'session_id' => $sid,
        'role' => 'user',
        'message' => 'What are the specs of the Aura X9 smartwatch?',
        'token_estimate' => 12,
    ]);

    $db->insert('chat_history', [
        'session_id' => $sid,
        'role' => 'system',
        'message' => rawMessage(),
        'message_type' => 'data_fetching',
        'tool_name' => 'search_web',
        'search_query' => TOPIC_QUERY,
        'source_map' => json_encode(['S1' => ['url' => 'https://example.com/aura-x9-specs', 'title' => 'Aura X9 — Full Specifications']]),
        'backing_chunks' => backingChunksJson(),
        'selected_chunks' => null,
        'raw_evicted' => 1,
        'atomic_context' => atomicContextJson(),
        'token_estimate' => 120,
    ]);

    $db->insert('chat_history', [
        'session_id' => $sid,
        'role' => 'assistant',
        'message' => 'The Aura X9 has a 46 mm titanium case and up to 3 days of battery life.',
        'token_estimate' => 18,
    ]);

    return $sid;
}

function cleanup(Database $db, int $sid): void
{
    $db->executeStatement('DELETE FROM chat_history WHERE session_id = ?', [$sid]);
    $db->executeStatement('DELETE FROM chat_sessions WHERE id = ?', [$sid]);
}

function firstPassToolNames(array $messages, ReflectionMethod $firstPass, ChatManager $cm): array
{
    $emit = function (string $event, array $data = []) {
    };
    $result = $firstPass->invoke($cm, $messages, $emit);
    $names = [];
    foreach (($result['tool_calls'] ?? []) as $tc) {
        $name = $tc['function']['name'] ?? '';
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

function buildMessages(PromptAssemblyService $pas, Database $db, int $sid, string $query): array
{
    $systemPrompt = $pas->buildSystemPrompt($query) . $pas->dateContextLine();
    $history = $db->selectSafe('chat_history', ['session_id' => $sid]);
    return $pas->buildMessagesArray($systemPrompt, $history);
}

// ---------------------------------------------------------------------------
// Case 1 (positive): follow-up detail lives only in retained chunks.
// ---------------------------------------------------------------------------

$sid = makeSession($db);

echo "\n--- CASE 1: follow-up on same topic -> search_session_evidence ---\n";

$routingOk = 0;
$routingTotal = 0;
for ($p = 1; $p <= $passes; $p++) {
    // Insert the follow-up as the current user turn each pass.
    $db->insert('chat_history', [
        'session_id' => $sid,
        'role' => 'user',
        'message' => FOLLOW_UP,
        'token_estimate' => 15,
    ]);
    $messages = buildMessages($pas, $db, $sid, FOLLOW_UP);
    $names = firstPassToolNames($messages, $firstPass, $cm);

    $routingTotal++;
    $ok = in_array('search_session_evidence', $names, true) && !in_array('search_web', $names, true);
    if ($ok) {
        $routingOk++;
    }
    printf("  pass %d: got=%s -> %s\n", $p, empty($names) ? '(none)' : implode(',', $names), $ok ? 'OK' : 'XX');
}
$check('follow-up routed to search_session_evidence (not search_web)', $routingOk === $routingTotal);

// Execution: rehydrate the retained chunk for real, against the fixture.
$events = [];
$emit = function (string $event, array $data = []) use (&$events) {
    $events[] = $event;
};
$result = $tes->executeToolByName(
    'search_session_evidence',
    ['queries' => ['battery capacity mAh']],
    $sid,
    $emit
);
$check('tool returned retained evidence text', stripos($result, 'SESSION EVIDENCE') !== false);
$check('tool rehydrated the exact mAh figure (420)', stripos($result, '420') !== false);
$check('retrieved source ids include S1', in_array('S1', $tes->getLastRetrievedSourceIds(), true));
$check('retained chunk provenance preserved (S1-C1 or S1-C2)', preg_match('/\[S1-C[12]\]/', $result) === 1);

cleanup($db, $sid);

// ---------------------------------------------------------------------------
// Case 2 (control): unrelated follow-up must still use search_web.
// ---------------------------------------------------------------------------

echo "\n--- CASE 2 (control): unrelated follow-up -> search_web ---\n";

$sid2 = makeSession($db);
$db->insert('chat_history', [
    'session_id' => $sid2,
    'role' => 'user',
    'message' => "What's the weather in Tokyo right now?",
    'token_estimate' => 12,
]);
$messages2 = buildMessages($pas, $db, $sid2, "What's the weather in Tokyo right now?");
$names2 = firstPassToolNames($messages2, $firstPass, $cm);
printf("  got=%s\n", empty($names2) ? '(none)' : implode(',', $names2));
$check('unrelated follow-up routed to search_web (not session_evidence)',
    in_array('search_web', $names2, true) && !in_array('search_session_evidence', $names2, true));

cleanup($db, $sid2);

echo "\n" . str_repeat('=', 60) . "\n";
printf("RESULT: %d passed, %d failed\n", $pass, $fail);
echo $fail === 0 ? "PASS\n" : "FAIL\n";
exit($fail === 0 ? 0 : 1);
