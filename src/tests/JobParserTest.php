<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\JobParser;

class JobParserTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runValidation();
        $this->runWorkModeLocation();
        $this->runNormalizeHtml();
        $this->runBridgeText();
        $this->runDescription();

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

    private function runValidation(): void
    {
        echo "\n=== mandatory field validation ===\n";

        $valid = [
            'title' => 'Senior PHP Developer',
            'company' => 'Acme GmbH',
            'posted_at' => '2026-08-01T09:00:00',
            'description' => 'Build things.',
            'work_mode' => 'remote',
            'applicant_count' => null,
            'url' => 'https://evil.example.com/spoof',
        ];

        $record = JobParser::validateRecord($valid, 'https://acme.at/jobs/1');
        $this->test('valid record passes', is_array($record));
        $this->testEq('url forced to fetched URL', 'https://acme.at/jobs/1', $record['url']);
        $this->testEq('source_domain derived from URL', 'acme.at', $record['source_domain']);

        $noDesc = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01',
        ], 'https://a.at/j/1');

        $withMeta = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01',
            'metadata' => ['foo' => 'bar'],
        ], 'https://a.at/j/1');

        $this->test('missing title rejected', JobParser::validateRecord(['company' => 'C', 'posted_at' => '2026-08-01'], 'https://a.at/j/1') === null);
        $this->test('missing company rejected', JobParser::validateRecord(['title' => 'T', 'posted_at' => '2026-08-01'], 'https://a.at/j/1') === null);
        $this->test('unparseable posted_at rejected', JobParser::validateRecord(['title' => 'T', 'company' => 'C', 'posted_at' => 'soon'], 'https://a.at/j/1') === null);
        $this->test('is_listing true rejected', JobParser::validateRecord(['title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'is_listing' => true], 'https://a.at/j/1') === null);
        $this->test('blank title rejected', JobParser::validateRecord(['title' => '  ', 'company' => 'C', 'posted_at' => '2026-08-01'], 'https://a.at/j/1') === null);
        $this->test('URL without host rejected', JobParser::validateRecord(['title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01'], 'not-a-url') === null);

        $collapsed = JobParser::validateRecord([
            'title' => "  Senior\n  Developer  ", 'company' => 'Acme', 'posted_at' => '2026-08-01',
        ], 'https://a.at/j/1');
    }

    private function runWorkModeLocation(): void
    {
        echo "\n=== work mode / location rule ===\n";

        $this->test('hybrid without city+country rejected', JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'hybrid',
        ], 'https://a.at/j/1') === null);

        $onsiteNoCountry = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'on_site', 'city' => 'Vienna',
        ], 'https://a.at/j/1');

        $hybridNoCity = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'hybrid', 'country' => 'Austria',
        ], 'https://a.at/j/1');

        $hybrid = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'hybrid', 'city' => 'Vienna', 'country' => 'Austria',
        ], 'https://a.at/j/1');

        $remote = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'Remote',
        ], 'https://a.at/j/1');
        $this->testEq('remote needs no location', 'remote', $remote['work_mode']);

        $onsite = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'on-site', 'city' => 'Graz', 'country' => 'AT',
        ], 'https://a.at/j/1');
        $this->testEq('on-site normalized to on_site', 'on_site', $onsite['work_mode']);

        $unknown = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01', 'work_mode' => 'flexible',
        ], 'https://a.at/j/1');
    }

    private function runNormalizeHtml(): void
    {
        echo "\n=== html normalization ===\n";

        $this->testEq('strips script/style/nav', 'keep', JobParser::normalizeHtml('<p>keep</p><script>var x=1;</script><style>.a{}</style><nav>menu</nav>'));
    }

    private function runBridgeText(): void
    {
        echo "\n=== bridge text extraction ===\n";

        $this->testEq('extracts entities[0].body', 'Job text', JobParser::extractBridgeText(['entities' => [['body' => 'Job text']]]));
    }

    private function runDescription(): void
    {
        echo "\n=== description multiline ===\n";

        $rec = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01',
            'description' => "Line one.\n\nLine two.\n- bullet a\n- bullet b",
        ], 'https://a.at/j/1');

        $rec2 = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01',
            'description' => "  spaced  \n\n\n   too many blanks  ",
        ], 'https://a.at/j/1');

        $rec3 = JobParser::validateRecord([
            'title' => 'T', 'company' => 'C', 'posted_at' => '2026-08-01',
            'description' => "Line1\r\nLine2\rLine3",
        ], 'https://a.at/j/1');
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
}
