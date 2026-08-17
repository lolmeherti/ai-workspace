<?php

declare(strict_types=1);

/*
 * Evidence-based context — Phase 8 model-use probe (MANUAL, live inference).
 *
 * The deterministic harness (evidence-context-eval.php) already proved Localsy
 * SUPPLIES the fact to the rendered prompt. This probe asks the only question
 * left: can the local model actually USE it? It splits infrastructure-limited
 * failure (fact absent from prompt) from model-limited / effective-context
 * failure (fact present, answer wrong).
 *
 * What it does per fixed case:
 *   1. Seed a controlled evidence block (data_fetching) + optional conversation.
 *   2. Build the REAL rendered prompt via PromptAssemblyService::buildMessagesArray
 *      (system + evidence guard + rolling conversation + untrusted evidence
 *      blocks, in production order).
 *   3. Record whether each required fact marker is present in that prompt.
 *   4. Ask the question against the live local model (AgentManager, non-streaming,
 *      temperature 0.0 for deterministic recall).
 *   5. Score the answer: required markers present, forbidden markers absent.
 *
 * NO web, NO tool loop, NO ChatManager — a deliberate, minimal system prompt is
 * used (not buildSystemPrompt) so the model answers from the supplied evidence
 * instead of routing to a tool. The evidence guard + untrusted-block injection
 * are the real components under test.
 *
 * Requires the llama.cpp server running (launcher boot). Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/evidence-model-probe.php
 *
 * Run the same fixture set against E4B / 12B / 26B by switching the model in
 * the dropdown (LLM_MODEL_NAME / LLM_CTX_SIZE in .env) and re-running.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Services\PromptAssemblyService;

const PROBE_TEMP = 0.0;

Config::load(dirname(__DIR__, 2));

$_ENV['CHAT_ROLLING_WINDOW_LIMIT'] = '15';
$_ENV['LLM_EVIDENCE_TOOL_ROLE'] = '0';

// PromptAssemblyService only needs a Database-typed stub for the constructor;
// buildMessagesArray never queries it, so the probe runs without MySQL.
class NoopDatabase extends \App\Database
{
    public function __construct()
    {
        // no parent call — never touch MySQL
    }

    public function query(string $sql, array $params = []): array
    {
        return [];
    }
}

function renderedText(array $messages): string
{
    $parts = [];
    foreach ($messages as $m) {
        $c = $m['content'] ?? '';
        if (is_string($c)) {
            $parts[] = $c;
        } elseif (is_array($c)) {
            foreach ($c as $part) {
                if (isset($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
        }
    }
    return implode("\n", $parts);
}

function contains(string $haystack, string $needle): bool
{
    return $needle !== '' && mb_stripos($haystack, $needle) !== false;
}

function evidence(string $message): array
{
    return ['role' => 'system', 'message' => $message, 'message_type' => 'data_fetching', 'active_context' => 1];
}

function turn(string $role, string $message): array
{
    return ['role' => $role, 'message' => $message, 'message_type' => 'text'];
}

$prompt = new PromptAssemblyService(new NoopDatabase(), sys_get_temp_dir(), fn(string $t): int => strlen($t));
$agent = new AgentManager();

$model = (string) Config::get('LLM_MODEL_NAME', '?');
$ctxSize = (string) Config::get('LLM_CTX_SIZE', '?');

$probeSystem = "You are a helpful assistant. Answer the user's question using ONLY the supplied evidence. "
    . "Do not search the web, browse, or call any tools. If the evidence does not contain the answer, say you do not know. Be concise.";

$probes = [
    [
        'name' => 'P1 direct factual recall',
        'conversation' => [],
        'evidence' => ['The capital of Austria is Vienna. <source id="S1">https://example/a</source>'],
        'question' => 'What is the capital of Austria?',
        'facts' => ['Vienna'],
        'required' => ['Vienna'],
        'forbidden' => [],
    ],
    [
        'name' => 'P2 numerical fact recall',
        'conversation' => [],
        'evidence' => ['The Q3 budget is 12345 euros. <source id="S1">https://example/a</source>'],
        'question' => 'What is the Q3 budget amount?',
        'facts' => ['12345'],
        'required' => ['12345'],
        'forbidden' => [],
    ],
    [
        'name' => 'P3 old fact + recent user constraint',
        'conversation' => [
            turn('user', 'Antworte ab jetzt auf Deutsch.'),
            turn('assistant', 'Verstanden.'),
        ],
        'evidence' => ["The user's preferred language is German. <source id=\"S1\">https://example/a</source>"],
        'question' => "What is the user's preferred language?",
        'facts' => ['German'],
        'required' => ['Deutsch'],
        'forbidden' => ['German'],
    ],
    [
        'name' => 'P4 combine two evidence blocks',
        'conversation' => [],
        'evidence' => [
            'The project lead is Dana. <source id="S1">https://example/a</source>',
            'Dana works in building BETA. <source id="S2">https://example/b</source>',
        ],
        'question' => 'Which building does the project lead work in?',
        'facts' => ['Dana', 'BETA'],
        'required' => ['BETA'],
        'forbidden' => [],
    ],
    [
        'name' => 'P5 respect a contradiction / caveat',
        'conversation' => [],
        'evidence' => ['The store opens at 9:00 on weekdays and at 11:00 on Sundays. <source id="S1">https://example/a</source>'],
        'question' => 'When does the store open on Sunday?',
        'facts' => ['9:00', '11:00'],
        'required' => ['11:00'],
        'forbidden' => ['9:00'],
    ],
    [
        'name' => 'P6 answer why we rejected X',
        'conversation' => [],
        'evidence' => ['Hiring record: applicant Smith was rejected because the salary demand was too high for the role. <source id="S1">https://example/a</source>'],
        'question' => 'Why was Smith rejected?',
        'facts' => ['salary'],
        'required' => ['salary'],
        'forbidden' => [],
    ],
    [
        'name' => 'P7 distractor-heavy recall',
        'conversation' => [],
        'evidence' => ['The capital of France is Paris, the capital of Spain is Madrid, the capital of Italy is Rome, the capital of Japan is Tokyo, the capital of Canada is Ottawa. TARGET: the company headquarters is in VIENNA_HQ. <source id="S1">https://example/a</source>'],
        'question' => 'Where is the company headquarters?',
        'facts' => ['VIENNA_HQ'],
        'required' => ['VIENNA_HQ'],
        'forbidden' => [],
    ],
    [
        'name' => 'P8 longer-context synthesis',
        'conversation' => [],
        'evidence' => ['Product NOVA_7 launches on 2026-12-01 at EUR 499. It ships in blue, black, and silver, with a 6.1-inch display and 128GB storage. <source id="S1">https://example/a</source>'],
        'question' => 'Give the launch date and price of NOVA_7.',
        'facts' => ['2026-12-01', '499'],
        'required' => ['2026-12-01', '499'],
        'forbidden' => [],
    ],
];

echo "============================================================\n";
echo "EVIDENCE MODEL-USE PROBE (live inference)\n";
echo "============================================================\n";
printf("model: %s | ctx: %s | temp: %.1f | probes: %d\n", $model, $ctxSize, PROBE_TEMP, count($probes));
echo "\n";

$results = [];
$pass = 0;
$fail = 0;

foreach ($probes as $probe) {
    echo str_repeat('-', 60) . "\n";
    echo $probe['name'] . "\n";
    echo "question: " . $probe['question'] . "\n";

    $evidenceRows = array_map(fn($msg) => evidence($msg), $probe['evidence']);
    $history = array_merge($probe['conversation'], $evidenceRows, [turn('user', $probe['question'])]);
    $messages = $prompt->buildMessagesArray($probeSystem, $history);
    $promptText = renderedText($messages);

    $factsInPrompt = [];
    foreach ($probe['facts'] as $f) {
        $inPrompt = contains($promptText, $f);
        $factsInPrompt[$f] = $inPrompt;
        printf("  fact in prompt: [%s] %s\n", $inPrompt ? 'YES' : 'NO', $f);
    }

    try {
        $answer = $agent->chat($messages, false, null, PROBE_TEMP);
    } catch (\Throwable $e) {
        $answer = 'ERROR: ' . $e->getMessage();
    }
    $answer = trim($answer);

    $missing = array_values(array_filter($probe['required'], fn($r) => !contains($answer, $r)));
    $leaked = array_values(array_filter($probe['forbidden'], fn($f) => contains($answer, $f)));
    $ok = empty($missing) && empty($leaked);

    printf("  required in answer: %s\n", empty($missing) ? 'all present' : 'MISSING: ' . implode(', ', $missing));
    printf("  forbidden in answer: %s\n", empty($leaked) ? 'none leaked' : 'LEAKED: ' . implode(', ', $leaked));
    printf("  model: %s | ctx: %s\n", $model, $ctxSize);

    $preview = mb_substr($answer, 0, 400);
    if (mb_strlen($answer) > 400) {
        $preview .= '... [truncated]';
    }
    echo "  answer: " . str_replace("\n", "\n          ", $preview) . "\n";
    printf("  -> %s\n", $ok ? 'PASS' : 'FAIL');

    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }

    $results[] = [
        'probe' => $probe['name'],
        'question' => $probe['question'],
        'model' => $model,
        'ctx_size' => $ctxSize,
        'facts_in_prompt' => $factsInPrompt,
        'required' => $probe['required'],
        'forbidden' => $probe['forbidden'],
        'answer' => $answer,
        'pass' => $ok,
    ];
}

echo "\n" . str_repeat('=', 60) . "\n";
printf("MODEL-USE PROBE: %d passed, %d failed, %d total (model: %s)\n", $pass, $fail, $pass + $fail, $model);
echo "\nInterpretation:\n";
echo "  fact ABSENT from prompt -> infrastructure failure (should never happen;\n";
echo "    the deterministic harness already covers that side)\n";
echo "  fact PRESENT but answer FAILS -> model-limited / effective-context failure\n";

$snapshot = sys_get_temp_dir() . '/evidence_probe_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $model) . '_' . date('Ymd_His') . '.json';
file_put_contents($snapshot, json_encode(['model' => $model, 'ctx_size' => $ctxSize, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nresults saved: {$snapshot}\n";

exit($fail === 0 ? 0 : 1);
