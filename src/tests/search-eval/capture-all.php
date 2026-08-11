<?php
/**
 * Phase 0: Batch Capture Runner
 *
 * Runs all 10 evaluation queries through the capture pipeline sequentially.
 * Run inside Docker container:
 *   php /var/www/html/tests/search-eval/capture-all.php
 */

require_once __DIR__ . '/capture.php';

$queries = [
    'specs-iphone'      => 'What are the iPhone 16 Pro camera specifications?',
    'long-article'      => 'What are the key findings from the Stack Overflow 2024 Developer Survey about AI tool usage?',
    'comparison-table'  => 'Compare the MacBook Air M4 and MacBook Pro M4 specifications: display, battery life, weight, and ports',
    'news-event'        => 'What happened during the global CrowdStrike Microsoft outage on July 19 2024?',
    'conflicting'       => 'Is dark chocolate healthy or unhealthy according to recent scientific research?',
    'blocked-page'      => 'What are the system requirements for Adobe Photoshop 2025?',
    'prompt-injection'  => 'What is prompt injection in large language models and how does it work?',
    'two-facts'         => 'What is the population of Tokyo Japan and what is the average annual rainfall in Tokyo?',
    'obscure-query'     => 'Who invented the modern retractable tape measure and when was it patented?',
    'code-docs'         => 'How do you create HTTP middleware in Go using the standard library net/http?',
];

$total = count($queries);
$successes = 0;
$failures = [];

echo "Phase 0: Capturing {$total} evaluation fixtures\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);

foreach ($queries as $id => $question) {
    echo str_repeat('-', 60) . "\n";
    echo "Query: {$id}\n";
    echo "Question: {$question}\n\n";

    $result = captureFixture($id, $question);

    if ($result['success']) {
        $successes++;
        echo "  OK\n";
    } else {
        $failures[$id] = $result['error'] ?? 'unknown error';
        echo "  FAILED: {$failures[$id]}\n";
    }

    echo "\n";
}

$elapsed = round((microtime(true) - $startTime) / 60, 1);

echo str_repeat('=', 60) . "\n";
echo "Results: {$successes}/{$total} captures succeeded\n";
echo "Elapsed: {$elapsed} minutes\n";

if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $id => $error) {
        echo "  {$id}: {$error}\n";
    }
    exit(1);
}

echo "\nAll fixtures captured successfully.\n";
exit(0);
