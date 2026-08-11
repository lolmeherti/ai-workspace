<?php
/**
 * Reddit adapter test — fetches via Localsy's Scraper::fetchRaw() and runs the parser.
 *
 * Usage (inside Docker):
 *   php test-reddit-adapter.php <reddit-url>
 *
 * Examples:
 *   php test-reddit-adapter.php "https://www.reddit.com/r/LocalLLaMA/comments/1sbpf86/best_gemma4_llamacpp_command/"
 *   php test-reddit-adapter.php "https://www.reddit.com/r/LocalLLaMA/"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Adapters\Reddit;
use App\Config;
use App\Scraper;

Config::load(__DIR__ . '/..');

$url = $argv[1] ?? null;
if (!$url) {
    fwrite(STDERR, "Usage: php test-reddit-adapter.php <reddit-url>\n");
    exit(1);
}

$adapter = new Reddit();
if (!$adapter->supports($url)) {
    fwrite(STDERR, "Not a Reddit URL: $url\n");
    exit(1);
}

echo "Fetching: $url\n";

$fetchMethod = '';
$result = Scraper::fetchRaw($url, $fetchMethod);

if ($result === null) {
    fwrite(STDERR, "Fetch failed: no response (both curl and FlareSolverr)\n");
    exit(1);
}

$bodyLen = strlen($result->body);
$visibleLen = strlen(strip_tags($result->body));
echo "Fetched via $fetchMethod — HTTP {$result->statusCode} — {$bodyLen}B body, {$visibleLen}B visible\n";

if ($result->statusCode !== 200) {
    fwrite(STDERR, "HTTP {$result->statusCode} — not parseable\n");
    exit(1);
}

if ($bodyLen === 0) {
    fwrite(STDERR, "Empty body\n");
    exit(1);
}

echo "Running parser...\n";

$start = microtime(true);

try {
    $parsed = $adapter->parse($url, $result->body);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

$elapsed = round((microtime(true) - $start) * 1000);

echo "\n=== PARSED ({$elapsed}ms) ===\n\n";
echo "Type:      {$parsed['type']}\n";
echo "Subreddit: {$parsed['subreddit']}\n";

if ($parsed['type'] === 'subreddit') {
    $postCount = count($parsed['posts']);
    echo "Posts:     {$postCount}\n\n";
    foreach ($parsed['posts'] as $i => $post) {
        echo str_repeat('-', 70) . "\n";
        echo "Post #" . ($i + 1) . "\n";
        echo "  ID:         {$post['id']}\n";
        echo "  Title:      {$post['title']}\n";
        echo "  Author:     {$post['author']}\n";
        echo "  Score:      " . ($post['score'] ?? 'null') . "\n";
        echo "  Comments:   " . ($post['comment_count'] ?? 'null') . "\n";
        echo "  Created:    " . ($post['created_at'] ?? 'null') . "\n";
        echo "  Flair:      " . ($post['flair'] ?? 'null') . "\n";
        $bodyPreview = mb_substr($post['body'], 0, 200);
        echo "  Body:       " . ($bodyPreview ?: '(empty)') . "\n";
    }
} else {
    $p = $parsed['post'];
    $commentCount = count($parsed['comments']);
    echo "Post ID:    {$p['id']}\n";
    echo "Title:      {$p['title']}\n";
    echo "Author:     {$p['author']}\n";
    echo "Score:      " . ($p['score'] ?? 'null') . "\n";
    echo "Comments:   " . ($p['comment_count'] ?? 'null') . "\n";
    echo "Created:    " . ($p['created_at'] ?? 'null') . "\n";
    echo "Flair:      " . ($p['flair'] ?? 'null') . "\n";
    $bodyPreview = mb_substr($p['body'], 0, 300);
    echo "Body:       " . ($bodyPreview ?: '(empty)') . "\n";
    echo "\nComments:   {$commentCount} top-level\n";

    foreach ($parsed['comments'] as $i => $c) {
        echo str_repeat('-', 70) . "\n";
        echo "Comment #" . ($i + 1) . "  [depth=0, score=" . ($c['score'] ?? 'null') . "]\n";
        echo "  Author: {$c['author']}\n";
        echo "  Body:   " . mb_substr($c['body'], 0, 200) . "\n";

        foreach ($c['replies'] as $j => $r) {
            echo "  -> Reply [depth=1, score=" . ($r['score'] ?? 'null') . "]\n";
            echo "     Author: {$r['author']}\n";
            echo "     Body:   " . mb_substr($r['body'], 0, 150) . "\n";
        }
    }
}

echo "\nDone.\n";

// ── Raw HTML diagnostics ────────────────────────────────────────────
echo "\n=== RAW HTML DIAGNOSTICS ===\n";
echo "Body size:    " . strlen($result->body) . "B\n";
echo "Visible text: " . strlen(strip_tags($result->body)) . "B\n";

preg_match('/<title>([^<]+)</', $result->body, $titleM);
echo "Page title:   " . ($titleM[1] ?? '(none)') . "\n";

$signals = [
    't3_'              => 't3_ post IDs',
    't1_'              => 't1_ comment IDs',
    'shreddit-post'    => 'shreddit-post element',
    'shreddit-comment' => 'shreddit-comment element',
    'post-title'       => 'post-title attribute',
    'rtjson-content'   => 'rtjson-content body',
    'permalink'        => 'permalink attribute',
    '"author"'         => 'author attribute',
    'recaptcha'        => 'reCAPTCHA',
    'prove your humanity' => 'humanity challenge',
    'cf-browser'       => 'Cloudflare challenge',
];
foreach ($signals as $needle => $label) {
    $found = str_contains(strtolower($result->body), strtolower($needle));
    printf("  %-25s %s\n", $label . ':', $found ? 'YES' : 'no');
}

// Dump first 1500 chars of visible text
$visible = trim(preg_replace('/\s+/', ' ', strip_tags($result->body)));
echo "\nVisible text (first 500 chars):\n";
echo substr($visible, 0, 500) . "\n";
