<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Database\FileAliasMap;
use App\Search\Bm25Retriever;
use App\Search\FileRetriever;

class StubDatabase extends Database
{
    public function __construct(private array $records)
    {
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->records;
    }
}

class FileRetrieverTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runAliasMap();
        $this->runRetrieval();
        $this->runTranslationAndVisibleTextFixtures();
        $this->runEntityTermDetection();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);

        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function rec(int $id, string $title, string $text, array $entities = []): array
    {
        return [
            'id' => $id,
            'original_name' => "file{$id}.txt",
            'physical_name' => "file{$id}.txt",
            'generated_title' => $title,
            'file_type' => 'text/plain',
            'uploaded_at' => '2024-01-01 00:00:00',
            'search_entities' => empty($entities) ? null : json_encode($entities),
            'searchable_text' => $text,
        ];
    }

    private function retriever(array $records): FileRetriever
    {
        return new FileRetriever(new StubDatabase($records));
    }

    private function runAliasMap(): void
    {
        echo "\n=== FileAliasMap::expand ===\n";
        $map = new FileAliasMap();

        $this->test('cv expands to resume', str_contains($map->expand('cv'), 'resume'));
        $this->test('cv expands to curriculum vitae', str_contains($map->expand('cv'), 'curriculum vitae'));
        $this->test('cv expands to lebenslauf', str_contains($map->expand('cv'), 'lebenslauf'));

        $this->test('lebenslauf expands to cv', str_contains($map->expand('lebenslauf'), 'cv'));
        $this->test('lebenslauf expands to curriculum vitae', str_contains($map->expand('lebenslauf'), 'curriculum vitae'));

        $this->test('rechnung expands to invoice', str_contains($map->expand('rechnung'), 'invoice'));
        $this->test('rechnung expands to bill', str_contains($map->expand('rechnung'), 'bill'));

        $this->test('x-ray expands to röntgen', str_contains($map->expand('x-ray'), 'röntgen'));

        $this->testEq('empty query unchanged', '', $map->expand(''));
        $this->testEq('no-alias query unchanged', 'quarterly report', $map->expand('quarterly report'));
        $this->testEq('word boundary: billion does not trigger bill', 'billion', $map->expand('billion'));
    }

    private function runRetrieval(): void
    {
        echo "\n=== FileRetriever (BM25 + grouping) ===\n";
        $this->runZeroResult();
        $this->runAliasRetrieval();
        $this->runEntityRetrieval();
        $this->runMultiChunkGrouping();
        $this->runTopThreeGrouping();
    }

    private function runZeroResult(): void
    {
        echo "\n--- zero-result ---\n";
        $records = [
            $this->rec(1, 'Quarterly Report', 'This is a financial document about revenue.'),
        ];

        $this->testEq('rank: no match returns empty', [], $this->retriever($records)->rank('zzzznotfound'));
        $this->testEq('rankAll: no match returns empty', [], $this->retriever($records)->rankAll('zzzznotfound'));
    }

    private function runAliasRetrieval(): void
    {
        echo "\n--- alias retrieval ---\n";
        $records = [
            $this->rec(1, 'Work History', 'This document is a curriculum vitae listing employment history and skills.'),
        ];

        $top = $this->retriever($records)->rank('cv');
        $this->test('cv query retrieves curriculum vitae file', count($top) === 1 && (int)$top[0]['id'] === 1);

        $topDe = $this->retriever($records)->rank('lebenslauf');
        $this->test('lebenslauf query retrieves English CV file', count($topDe) === 1 && (int)$topDe[0]['id'] === 1);
    }

    private function runEntityRetrieval(): void
    {
        echo "\n--- entity / identifier retrieval ---\n";
        $records = [
            $this->rec(1, 'Blood Test', 'Blutbild vom 12.03.2024', ['hemoglobin', '12.03.2024']),
        ];

        $top = $this->retriever($records)->rank('12.03.2024');
        $this->test('identifier in entities+text retrieved', count($top) === 1 && (int)$top[0]['id'] === 1);

        $records2 = [
            $this->rec(2, 'Medical Report', 'The patient visit on 12.03.2024 shows normal values.'),
        ];
        $top2 = $this->retriever($records2)->rank('12.03.2024');
        $this->test('identifier in body only still retrieved', count($top2) === 1 && (int)$top2[0]['id'] === 2);
    }

    private function runMultiChunkGrouping(): void
    {
        echo "\n--- multi-chunk grouping ---\n";
        $para = str_repeat('project ', 250);
        $long = implode("\n\n", array_fill(0, 8, $para));

        $records = [
            $this->rec(1, 'Project Plan', $long),
        ];

        $all = $this->retriever($records)->rankAll('project');
        $this->testEq('multi-chunk file collapses to one result', 1, count($all));
        $this->test('multi-chunk result has correct id', (int)$all[0]['id'] === 1);
    }

    private function runTranslationAndVisibleTextFixtures(): void
    {
        echo "\n--- visible-text-only identifier + German->English retrieval ---\n";

        // Item 4: identifier present ONLY in visible_text (body), omitted from both
        // description and search_entities — must still retrieve (raw-preserves-facts).
        $records = [
            $this->rec(1, 'Invoice Scan', "Visible text: INV-2024-9911\nA paper invoice from a vendor.", []),
        ];
        $top = $this->retriever($records)->rank('INV-2024-9911');
        $this->test('identifier in body only (not entities, not description) retrieves', count($top) === 1 && (int)$top[0]['id'] === 1);

        // Item 5: German original + English translation searchable by the English term.
        $records = [
            $this->rec(2, 'Arztbrief', "Blutbild vom 12.03.2024\nBlood count from 12.03.2024\nA medical blood test report.", []),
        ];
        $top = $this->retriever($records)->rank('blood count');
        $this->test('German original + English translation retrievable by English term', count($top) === 1 && (int)$top[0]['id'] === 2);

        // Title-only term regression: a term present ONLY in generated_title (absent
        // from the body) must still retrieve — title is the highest-weight field.
        $records = [
            $this->rec(3, 'Curriculum Vitae - John Smith', 'John Smith. Software engineer with eight years of experience in web development and Python.'),
        ];
        $top = $this->retriever($records)->rank('curriculum vitae');
        $this->test('title-only term retrieves (title field not zeroed by body-only IDF)', count($top) === 1 && (int)$top[0]['id'] === 3);
    }

    private function runEntityTermDetection(): void
    {
        echo "\n--- entity term detection (capitalized names from raw query) ---\n";

        $bm25 = new Bm25Retriever();
        $ref = new \ReflectionMethod(Bm25Retriever::class, 'entityTerms');

        $terms = $ref->invoke($bm25, ['siemens' => 1.0], 'Siemens invoice');
        $this->test('capitalized name detected from raw query', in_array('siemens', $terms, true));

        $terms2 = $ref->invoke($bm25, ['röntgen' => 1.0], 'Röntgen Thorax');
        $this->test('umlaut capitalized name detected', in_array('röntgen', $terms2, true));

        $terms3 = $ref->invoke($bm25, [], 'AG');
        $this->test('short capitalized token (AG) not treated as entity', !in_array('ag', $terms3, true));

        $terms4 = $ref->invoke($bm25, ['12.03.2024' => 1.0], '');
        $this->test('digit term still detected from token vector', in_array('12.03.2024', $terms4, true));

        $terms5 = $ref->invoke($bm25, ['cv' => 1.0], 'cv');
        $this->testEq('no-digit no-capitalized query yields no entity terms', [], $terms5);
    }

    private function runTopThreeGrouping(): void
    {
        echo "\n--- top-three grouping ---\n";
        $records = [
            $this->rec(1, 'alpha', 'x'),
            $this->rec(2, 'alpha', 'x'),
            $this->rec(3, 'alpha', 'x'),
            $this->rec(4, 'report', 'alpha'),
        ];

        $retriever = $this->retriever($records);

        $top = $retriever->rank('alpha');
        $topIds = array_map(fn($r) => (int)$r['id'], $top);
        $this->testEq('rank returns exactly 3', 3, count($top));
        $this->test(
            'title-matched files retained',
            in_array(1, $topIds, true) && in_array(2, $topIds, true) && in_array(3, $topIds, true)
        );
        $this->test('body-only file excluded from top 3', !in_array(4, $topIds, true));

        $all = $retriever->rankAll('alpha');
        $allIds = array_map(fn($r) => (int)$r['id'], $all);
        $this->testEq('rankAll returns all 4 matching', 4, count($all));
        $this->test('rankAll includes body-only file', in_array(4, $allIds, true));
        $this->testEq('no duplicate ids in rankAll', 4, count(array_unique($allIds)));
    }
}
