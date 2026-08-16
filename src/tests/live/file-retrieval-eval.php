<?php

declare(strict_types=1);

/**
 * Standalone file-search retrieval benchmark.
 *
 * Creates real temp .txt files, extracts their text through the real
 * FileExtractor, then ranks a ladder of queries (easy -> hard) through the
 * real FileRetriever and scores hit rates per tier and overall.
 *
 * Deterministic: no LLM calls (title/translation are supplied per file as a
 * stand-in for the ingestion prompt), no writes to the real uploaded_files
 * table. Run: docker exec ai_php_web php /var/www/html/tests/live/file-retrieval-eval.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\FileExtractor;
use App\Search\FileRetriever;

class BenchStubDatabase extends Database
{
    public function __construct(private array $records)
    {
        // no parent call — never touch MySQL
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->records;
    }
}

// --- corpus: filename => [generated_title, content] ---
$corpus = [
    ['cv_john_smith.txt', 'Curriculum Vitae - John Smith',
        "Curriculum Vitae\nJohn Smith\nSoftware engineer with eight years of experience. Work history includes web development, Python, and React. Education in computer science."],
    ['blood_test.txt', 'Blood Test Results',
        "Blood test results. Blutbild vom 12.03.2024. Hemoglobin 14.2, leukocytes 6.1. Routine checkup."],
    ['colonoscopy.txt', 'Colonoscopy Report',
        "Colonoscopy report from West Clinic. A polyp was found in the sigmoid colon and removed. Biopsy pending."],
    ['invoice_siemens.txt', 'Siemens Invoice',
        "Invoice 2026-1841 from Siemens AG. Total amount EUR 147.52 for network equipment. Payment terms fourteen days."],
    ['xray_thorax.txt', 'Chest X-Ray',
        "Chest X-ray of the thorax. Röntgen Thorax showing no acute abnormalities."],
    ['bank_statement.txt', 'Erste Bank Statement',
        "Bank statement from Erste Bank for account 123456789. Monthly transactions and closing balance."],
    ['employment_contract.txt', 'Employment Contract',
        "Employment contract. Salary, start date, and notice period are specified."],
    ['rental_agreement.txt', 'Rental Agreement',
        "Rental agreement for an apartment in Vienna. Monthly rent and deposit are listed."],
    ['lab_cholesterol.txt', 'Lab Results - Cholesterol',
        "Lab results showing cholesterol levels. LDL and HDL values with a total cholesterol measurement."],
    ['insurance_letter.txt', 'Insurance Letter',
        "Insurance letter regarding a policy number and coverage details. Renewal date and premium are stated."],
];

// --- query ladder: [tier, query, expected file ids] ---
$ladder = [
    [1, 'colonoscopy', [3]],
    [1, 'cholesterol', [9]],
    [1, 'Siemens', [4]],

    [2, 'cv', [1]],
    [2, 'resume', [1]],
    [2, 'invoice', [4]],
    [2, 'rechnung', [4]],
    [2, 'x-ray', [5]],
    [2, 'röntgen', [5]],

    [3, '12.03.2024', [2]],
    [3, '147.52', [4]],
    [3, 'Erste Bank', [6]],
    [3, 'blutbild', [2]],
    [3, 'polyp', [3]],

    [4, 'coder', [1]],
    [4, 'money owed', [4]],
    [4, 'doctor referral', [2]],
];

$tierNames = [
    1 => 'easy (exact word)',
    2 => 'medium (alias / German)',
    3 => 'hard (identifier / name)',
    4 => 'semantic (beyond lexical BM25)',
];

// --- build temp files + extract real text ---
$tmp = sys_get_temp_dir() . '/file_retrieval_bench_' . uniqid();
mkdir($tmp, 0777, true);

$records = [];
$id = 0;

echo "=== RAW EXTRACTED TEXT (FileExtractor) ===\n";
foreach ($corpus as [$name, $title, $content]) {
    $id++;
    $path = $tmp . '/' . $name;
    file_put_contents($path, $content);
    $extracted = FileExtractor::extractText($path, $name) ?? '';

    echo sprintf("#%-2d %-22s [%s]\n", $id, $name, $title);
    $preview = mb_substr($extracted, 0, 100);
    echo "      " . $preview . (mb_strlen($extracted) > 100 ? '…' : '') . "\n";

    $records[] = [
        'id' => $id,
        'original_name' => $name,
        'physical_name' => $name,
        'generated_title' => $title,
        'file_type' => 'text/plain',
        'uploaded_at' => '2024-01-01 00:00:00',
        'search_entities' => null,
        'searchable_text' => $extracted,
    ];
}

$retriever = new FileRetriever(new BenchStubDatabase($records));

// --- run the ladder ---
echo "\n=== RETRIEVAL (easy -> hard) ===\n";
printf("%-16s %-9s %-30s %-30s %-30s %-4s\n", 'QUERY', 'EXPECTED', 'RANK 1', 'RANK 2', 'RANK 3', 'HIT');
echo str_repeat('-', 122) . "\n";

$tierHits = [];
$tierTotal = [];
foreach ($ladder as [$tier, $query, $expected]) {
    $top = $retriever->rank($query);
    $topIds = array_map(fn($r) => (int)$r['id'], $top);
    $hit = (bool) array_intersect($topIds, $expected);

    $rank = array_map(
        fn($r) => '#' . $r['id'] . ' ' . $r['generated_title'],
        $top
    );
    while (count($rank) < 3) $rank[] = '-';

    printf(
        "%-16s %-9s %-30s %-30s %-30s %-4s\n",
        $query,
        implode(',', $expected),
        $rank[0],
        $rank[1],
        $rank[2],
        $hit ? 'yes' : 'no'
    );

    $tierHits[$tier] = ($tierHits[$tier] ?? 0) + ($hit ? 1 : 0);
    $tierTotal[$tier] = ($tierTotal[$tier] ?? 0) + 1;
}

// --- summary ---
echo str_repeat('-', 122) . "\n";
$overallHits = 0;
$overallTotal = 0;
foreach ($tierNames as $tier => $label) {
    $h = $tierHits[$tier] ?? 0;
    $t = $tierTotal[$tier] ?? 0;
    $pct = $t > 0 ? round(100 * $h / $t) : 0;
    printf("tier %d  %-28s %d/%d  (%d%%)\n", $tier, $label, $h, $t, $pct);
    $overallHits += $h;
    $overallTotal += $t;
}
$overallPct = $overallTotal > 0 ? round(100 * $overallHits / $overallTotal) : 0;
printf("\nOVERALL: %d/%d  (%d%%)\n", $overallHits, $overallTotal, $overallPct);

$lexHits = $overallHits - ($tierHits[4] ?? 0);
$lexTotal = $overallTotal - ($tierTotal[4] ?? 0);
$lexPct = $lexTotal > 0 ? round(100 * $lexHits / $lexTotal) : 0;
printf("LEXICAL (tiers 1-3, what BM25 is designed for): %d/%d  (%d%%)\n", $lexHits, $lexTotal, $lexPct);

// --- cleanup ---
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);
