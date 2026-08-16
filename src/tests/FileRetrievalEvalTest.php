<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Search\FileRetriever;

class EvalStubDatabase extends Database
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

/**
 * Retrieval quality benchmark over a realistic simulated corpus (self-created
 * fixtures, no real user data). Prints the ranked results for a set of labeled
 * queries plus recall@1 / recall@3, so ranking quality and the length-bias
 * baseline can be eyeballed directly. Informational — always "passes".
 */
class FileRetrievalEvalTest
{
    private array $records = [];
    private FileRetriever $retriever;

    public function run(): bool
    {
        $this->buildCorpus();
        $this->retriever = new FileRetriever(new EvalStubDatabase($this->records));

        $queries = $this->queries();

        echo "\n=== File Retrieval Quality Evaluation ===\n";
        printf("corpus: %d files, %d labeled queries\n\n", count($this->records), count($queries));
        printf("%-16s %-9s %-26s %-26s %-26s %-6s %-6s\n", 'QUERY', 'EXPECTED', 'RANK 1', 'RANK 2', 'RANK 3', 'HIT@1', 'HIT@3');
        echo str_repeat('-', 122) . "\n";

        $hit1 = 0;
        $hit3 = 0;
        $total = 0;

        foreach ($queries as [$query, $expected, $label]) {
            $top = $this->retriever->rank($query);
            $topIds = array_map(fn($r) => (int)$r['id'], $top);

            $isHit1 = !empty($topIds) && in_array($topIds[0], $expected, true);
            $isHit3 = (bool) array_intersect($topIds, $expected);
            if ($isHit1) $hit1++;
            if ($isHit3) $hit3++;
            $total++;

            $rank = array_map(fn($r) => $this->short($r['generated_title'], 24), $top);
            while (count($rank) < 3) $rank[] = '-';

            printf(
                "%-16s %-9s %-26s %-26s %-26s %-6s %-6s\n",
                $label,
                implode(',', $expected),
                $rank[0],
                $rank[1],
                $rank[2],
                $isHit1 ? 'yes' : 'no',
                $isHit3 ? 'yes' : 'no'
            );
        }

        echo str_repeat('-', 122) . "\n";
        printf("recall@1: %d/%d, recall@3: %d/%d\n", $hit1, $total, $hit3, $total);

        $this->lengthBiasFixture();

        echo "\n";
        return true;
    }

    private function lengthBiasFixture(): void
    {
        echo "\n--- length-bias fixture (query 'project') ---\n";
        $short = $this->rec(14, 'Meeting Whiteboard Photo', 'A photo of a whiteboard from the project kickoff meeting. Some notes are visible.', []);
        $para = 'The project status update covers milestones, budget, and risks for the project team during the project review. ';
        $long = $this->rec(13, 'Quarterly Financial Report', str_repeat($para, 60), []);

        $retriever = new FileRetriever(new EvalStubDatabase([$short, $long]));
        $order = array_map(fn($r) => $r['generated_title'], $retriever->rankAll('project'));
        echo "rank order: " . implode(' > ', $order) . "\n";
        echo "(short image vs long document; the winner reveals whether length normalization biases ranking)\n";
    }

    private function buildCorpus(): void
    {
        $this->records = [
            $this->rec(1, 'Curriculum Vitae - John Smith',
                'John Smith. Software engineer with eight years of experience. Work history includes web development, Python, and React. Education in computer science.', []),
            $this->rec(2, 'Lebenslauf',
                'Resume of Anna Muller. Experience in marketing and project management across three companies in Vienna. Skills in communication and leadership.', []),
            $this->rec(3, 'Blood Test Results',
                "Blutbild vom 12.03.2024\nBlood count from 12.03.2024\nA medical blood test report listing hemoglobin and leukocyte values from a routine checkup.",
                ['hemoglobin', '12.03.2024']),
            $this->rec(4, 'Colonoscopy Report',
                'Colonoscopy report from West Clinic. A polyp was found in the sigmoid colon and removed. Biopsy results pending.', []),
            $this->rec(5, 'Siemens Invoice',
                "Rechnung\nInvoice 2026-1841 from Siemens AG. Total amount EUR 147.52 for network equipment. Payment terms fourteen days.",
                ['Siemens AG', 'EUR 147.52', '2026-1841']),
            $this->rec(6, 'Chest X-Ray',
                "Röntgen Thorax\nChest X-ray of the thorax showing no acute abnormalities. Impression: normal.", []),
            $this->rec(7, 'Erste Bank Statement',
                'Bank statement from Erste Bank for account 123456789. Lists monthly transactions and closing balance.', []),
            $this->rec(8, 'Referral Letter',
                'Referral letter to a specialist for further evaluation. Patient history and reason for referral included.', []),
            $this->rec(9, 'Employment Contract',
                'Employment contract between the employee and employer. Salary, start date, and notice period are specified.', []),
            $this->rec(10, 'Rental Agreement',
                'Rental agreement for an apartment in Vienna. Monthly rent and deposit amount are listed.', []),
            $this->rec(11, 'Lab Results - Cholesterol',
                'Lab results showing cholesterol levels. LDL and HDL values with a total cholesterol measurement.', []),
            $this->rec(12, 'Insurance Letter',
                'Insurance letter regarding a policy number and coverage details. Renewal date and premium are stated.', []),
        ];
    }

    private function queries(): array
    {
        return [
            ['cv', [1, 2], 'cv'],
            ['lebenslauf', [1, 2], 'lebenslauf'],
            ['resume', [1, 2], 'resume'],
            ['blood test', [3], 'blood test'],
            ['blutbild', [3], 'blutbild'],
            ['colonoscopy', [4], 'colonoscopy'],
            ['polyp', [4], 'polyp'],
            ['invoice', [5], 'invoice'],
            ['rechnung', [5], 'rechnung'],
            ['x-ray', [6], 'x-ray'],
            ['röntgen', [6], 'röntgen'],
            ['Siemens', [5], 'Siemens'],
            ['Erste Bank', [7], 'Erste Bank'],
            ['12.03.2024', [3], '12.03.2024'],
            ['147.52', [5], '147.52'],
            ['cholesterol', [11], 'cholesterol'],
        ];
    }

    private function rec(int $id, string $title, string $text, array $entities = []): array
    {
        return [
            'id' => $id,
            'original_name' => "file{$id}",
            'physical_name' => "file{$id}",
            'generated_title' => $title,
            'file_type' => 'text/plain',
            'uploaded_at' => '2024-01-01 00:00:00',
            'search_entities' => empty($entities) ? null : json_encode($entities),
            'searchable_text' => $text,
        ];
    }

    private function short(string $s, int $max): string
    {
        $s = trim($s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
