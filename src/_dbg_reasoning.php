<?php
$url = 'http://172.29.80.1:1234/v1/chat/completions';
$sys = "Extract facts as [S1] claim lines only.";
$user = "The Knicks won the 2026 NBA finals 4-1 against the Lakers. Curry scored 44 points. Brunson had 12 assists. The game went to overtime.";

function probe($label, $extra) {
    global $url, $sys, $user;
    $payload = [
        'model' => 'local',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
        'max_tokens' => 1024,
        'stream' => false,
    ];
    if ($extra !== null) $payload = array_merge($payload, $extra);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 120]);
    $r = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($r, true);
    $m = $d['choices'][0]['message'] ?? [];
    $content = $m['content'] ?? '';
    $reason = $m['reasoning_content'] ?? '';
    $finish = $d['choices'][0]['finish_reason'] ?? '?';
    $ct = $d['usage']['completion_tokens'] ?? -1;
    echo "[$label] finish=$finish completion_tokens=$ct content_len=" . strlen($content) . " reasoning_len=" . strlen($reason) . "\n";
}

probe('reasoning_budget=128 (top-level)', ['reasoning_budget' => 128]);
probe('reasoning_effort=none', ['reasoning_effort' => 'none']);
probe('reasoning_effort=low', ['reasoning_effort' => 'low']);
