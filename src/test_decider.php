<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\AgentManager;
use App\Agents\SearchDecider;

Config::load(__DIR__);

$agentManager = new AgentManager();
$decider = new SearchDecider($agentManager);

$testSuite = [
    "hello, how are you today?",
    "okay that really works nicely",
    "can you write a simple php function to reverse a string?",
    "what is 25 * 40?",
    "who won the most recent formula 1 race?",
    "current stock price of NVIDIA",
    "latest news about the temporary ceasefire",
    "explain how recursion works in programming"
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Decider Diagnostic Suite (JSON Edition)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 p-8 font-sans">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-2 text-cyan-400">Search Decider Diagnostics (JSON)</h1>
        <p class="text-slate-400 text-sm mb-6">Evaluating structured JSON-tool calling patterns across diverse prompt vectors.</p>
        
        <div class="space-y-4">
            <?php foreach ($testSuite as $index => $prompt): ?>
                <?php
                $currentDate = date('l, F j, Y g:i A');
                $systemPrompt = "Today is {$currentDate}.\n\n" .
                                "Return ONLY JSON matching this schema:\n" .
                                "{\n" .
                                "  \"requires_search\": boolean,\n" .
                                "  \"search_query\": string or null\n" .
                                "}\n\n" .
                                "Set requires_search to true only if the user query requires up-to-date live facts, current weather, or news. Set to false for code, greetings, math, or reasoning.";

                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt]
                ];

                $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.1);
                $rawResponse = trim($agentManager->chat($messages, false, null, $temperature));
                
                $responseToParse = $rawResponse;
                if (strpos($responseToParse, '```') !== false) {
                    $responseToParse = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $responseToParse);
                    $responseToParse = trim($responseToParse);
                }

                $data = json_decode($responseToParse, true);
                $requiresSearch = false;
                $searchQuery = null;

                if (is_array($data) && isset($data['requires_search']) && $data['requires_search'] === true) {
                    $requiresSearch = true;
                    $searchQuery = $data['search_query'] ?? null;
                }
                ?>
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-5">
                    <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-800">
                        <span class="text-xs font-bold text-slate-500 uppercase">Test #<?php echo ($index + 1); ?></span>
                        <?php if ($requiresSearch): ?>
                            <span class="text-xs px-2.5 py-1 rounded bg-rose-500/10 text-rose-400 border border-rose-500/30 font-semibold">
                                Triggered Search
                            </span>
                        <?php else: ?>
                            <span class="text-xs px-2.5 py-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 font-semibold">
                                Handled Locally (NONE)
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-slate-500 block text-xs font-semibold uppercase tracking-wider mb-1">User Input</span>
                            <span class="text-slate-200">"<?php echo htmlspecialchars($prompt); ?>"</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block text-xs font-semibold uppercase tracking-wider mb-1">Raw JSON Output</span>
                            <pre class="bg-slate-950 p-3 rounded text-cyan-400 font-mono select-all overflow-x-auto"><?php echo htmlspecialchars($rawResponse); ?></pre>
                        </div>
                        <div>
                            <span class="text-slate-500 block text-xs font-semibold uppercase tracking-wider mb-1">Parsed Decision</span>
                            <?php if ($requiresSearch): ?>
                                <span class="text-amber-400 font-medium">Search Query: "<?php echo htmlspecialchars($searchQuery); ?>"</span>
                            <?php else: ?>
                                <span class="text-emerald-400 font-medium">No Search Required (Parsed JSON fields matched successfully)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>