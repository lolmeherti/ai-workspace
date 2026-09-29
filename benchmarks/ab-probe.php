<?php
// Controlled A/B: same answer-pass prompt under OLD shape (no tools) vs NEW
// shape (tools + tool_choice=none). Isolates the quality/reliability effect of
// putting the tool list on the answer pass. Not part of the app; delete after.
require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\AgentManager;
use App\ChatManager;
use App\Logger;
use App\Services\PromptAssemblyService;

Config::load(__DIR__);
$db = new Database();
Logger::setDatabase($db);
$agent = new AgentManager();
$chat = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, '/var/www/html/uploads');

// Real tool schemas (private method, via reflection).
$ref = new ReflectionMethod(ChatManager::class, 'buildToolSchemas');
$ref->setAccessible(true);
$tools = $ref->invoke($chat, false);
echo "tools loaded: " . count($tools) . " schemas\n";

// Faithful answer-pass message assembly: system + current user turn (with
// runtime time) + evidence block + runtime reminder.
function buildMessages(PromptAssemblyService $pas, string $q, string $evidence, string $fetchedAt): array
{
    $system = $pas->buildSystemPrompt($q, false);
    $time = $pas->currentTimeContextLine();
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $time . "\n\n" . $q],
    ];
    $block = $pas->buildEvidenceBlock($evidence, [], $fetchedAt);
    if (($block['content'] ?? '') !== '') {
        $messages[] = $block;
    }
    $messages[] = ['role' => 'user', 'content' => "RUNTIME REMINDER:\nAnswer the user's original request:\n\"{$q}\""];
    return $messages;
}

$cases = [
    [
        'q' => "What's on my agenda for the next few days?",
        'evidence' => "System fetched upcoming tasks from Todoist: - [ID 6hMpP9] Anjali: Blutabnahme (Today, 08:30) - [ID x2] Anjali: Diabetes discussion (Sep 29) - [ID x3] Buy a birthday gift for wife (overdue, was due Sep 15). Note: 16 more tasks are not shown here.",
    ],
    [
        'q' => "What is the capital of France and what river runs through it?",
        'evidence' => "Web search results: Paris is the capital and most populous city of France. The Seine river flows through Paris.",
    ],
    [
        'q' => "What time is my dentist appointment tomorrow?",
        'evidence' => "System fetched upcoming tasks from Todoist: - Grocery shopping (today, 17:00) - Call mom (tomorrow). (No dentist appointment was found in the task list.)",
    ],
];

$temp = 0.7;
$samples = 3;
$maxTokens = 1024;

function leakFlag(string $a): string
{
    if (preg_match('/<tool_call|function=|parameter=|\["[^"]*"\s*,/i', $a)) {
        return 'LEAK';
    }
    return 'ok';
}

foreach ($cases as $ci => $c) {
    echo "\n\n################ CASE " . ($ci + 1) . ": {$c['q']} ################\n";
    $messages = buildMessages($pas, $c['q'], $c['evidence'], '2026-09-22 20:00:00');
    for ($s = 1; $s <= $samples; $s++) {
        $old = trim($agent->chat($messages, false, null, $temp, 'ab_old', null, null, $maxTokens));
        $new = trim($agent->chat($messages, false, null, $temp, 'ab_new', null, null, $maxTokens, $tools, 'none'));
        echo "\n--- sample {$s}  OLD (no tools) [" . leakFlag($old) . "] ---\n" . $old . "\n";
        echo "--- sample {$s}  NEW (tools+none) [" . leakFlag($new) . "] ---\n" . $new . "\n";
    }
}
