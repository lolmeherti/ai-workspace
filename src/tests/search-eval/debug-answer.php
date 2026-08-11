<?php
require_once __DIR__ . '/../../vendor/autoload.php';
\App\Config::load(__DIR__ . '/../..');

$a = new \App\AgentManager();

$evidence = file_get_contents(__DIR__ . '/blocked-page/phase1-evidence.txt');
$question = trim(file_get_contents(__DIR__ . '/blocked-page/original-question.txt'));

$sys = "You are a helpful AI assistant. Answer using only the RETRIEVED DATA. Cite source IDs [S1] where applicable.";
$user = $evidence . "\n\nUSER QUESTION: " . $question .
        "\n\nProvide a thorough answer based on the RETRIEVED DATA above.";

$result = $a->chat([
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user', 'content' => $user]
], false, null, 0.5);

echo "LEN=" . strlen($result) . "\n";
echo "PREVIEW=" . substr($result, 0, 300) . "\n";
