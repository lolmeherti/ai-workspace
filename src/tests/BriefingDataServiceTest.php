<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\BriefingDataService;

/**
 * BriefingDataService — deterministic, no DB/LLM.
 *
 * Covers normalizeEmails (dedup/sort/cap/ids), cleanBody (HTML/entities/
 * quoted-thread/signature), budget math, fit check, calendar partition, and
 * preview building.
 */
class BriefingDataServiceTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== normalizeEmails ===\n";
        $this->testNormalize();

        echo "\n=== cleanBody ===\n";
        $this->testCleanBody();

        echo "\n=== budget + fit ===\n";
        $this->testBudget();

        echo "\n=== partitionCalendar ===\n";
        $this->testCalendar();

        echo "\n=== buildPreview ===\n";
        $this->testPreview();

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

    private function testNormalize(): void
    {
        $email = static function (int $account, string $uid, string $date): array {
            return [
                'account_id' => $account,
                'uid'        => $uid,
                'account_label' => 'box',
                'account_email' => 'a@b.c',
                'from'       => 'Sender',
                'subject'    => 'Subject',
                'date'       => $date,
                'snippet'    => 'snippet',
            ];
        };

        // dedup: same (account, uid) twice
        $raw = [$email(1, 'a', '2026-08-27 09:00:00'), $email(1, 'a', '2026-08-27 09:00:00')];
        [$emails, , $omitted] = BriefingDataService::normalizeEmails($raw);
        $this->testEq('dedup collapses duplicate (account, uid)', 1, count($emails));
        $this->testEq('no omission for a small set', 0, $omitted);

        // sort: oldest input first -> newest output first
        $raw = [
            $email(1, 'old', '2026-08-26 09:00:00'),
            $email(2, 'new', '2026-08-27 09:00:00'),
            $email(1, 'mid', '2026-08-27 00:00:00'),
        ];
        [$emails, , ] = BriefingDataService::normalizeEmails($raw);
        $this->testEq('newest first', '2026-08-27 09:00:00', $emails[0]['date']);
        $this->testEq('ids assigned 1..N in order', [1, 2, 3], array_column($emails, 'id'));

        // cap at MAX_EMAILS
        $many = [];
        for ($i = 0; $i < 32; $i++) {
            $day = $i < 24 ? '27' : '26';
            $many[] = $email(1, "u{$i}", sprintf('2026-08-%s %02d:00:00', $day, $i % 24));
        }
        [$emails, , $omitted] = BriefingDataService::normalizeEmails($many);
        $this->testEq('cap at 30', 30, count($emails));
        $this->testEq('omitted count is 2', 2, $omitted);
        $this->testEq('kept the newest (Aug 27 23:00)', '2026-08-27 23:00:00', $emails[0]['date']);

        // errors are separated, not assigned ids
        $raw = [
            $email(1, 'ok', '2026-08-27 09:00:00'),
            ['account_id' => 2, 'account_label' => 'bad', 'account_email' => 'b@b.b', 'error' => 'AUTH_FAILED'],
        ];
        [$emails, $errors, ] = BriefingDataService::normalizeEmails($raw);
        $this->testEq('one valid email', 1, count($emails));
        $this->testEq('one error carried through', 1, count($errors));
    }

    private function testCleanBody(): void
    {
        $this->testEq('strips HTML tags', 'Hello world', BriefingDataService::cleanBody('<p>Hello <b>world</b></p>'));
        $this->testEq('decodes entities', 'A & B', BriefingDataService::cleanBody('A &amp; B'));

        $quotedEn = "Real content\n\nOn Thu, Aug 27, 2026 at 10:00 AM, John <j@x.com> wrote:\n> old text\n> more";
        $this->testEq('drops EN quoted-thread markers', 'Real content', BriefingDataService::cleanBody($quotedEn));

        $quotedDe = "Echte Info\n\nAm 27.08.2026 um 10:00 schrieb John:\n> alte Zeile";
        $this->testEq('drops DE quoted-thread markers', 'Echte Info', BriefingDataService::cleanBody($quotedDe));

        $sig = "Body here\n--\nRegards,\nJohn Doe";
        $this->testEq('truncates at signature delimiter', 'Body here', BriefingDataService::cleanBody($sig));

        $ws = "Line  one   two\n\n\n\nLine three";
        $this->testEq('collapses whitespace + blank runs', "Line one two\n\nLine three", BriefingDataService::cleanBody($ws));

        $this->testEq('empty input stays empty', '', BriefingDataService::cleanBody(''));
    }

    private function testBudget(): void
    {
        $this->testEq('budget reserves output+safety (16384)', (16384 - 4096 - 1024) * 4, BriefingDataService::computeBudgetChars(16384));
        $this->testEq('tiny context floors at 0', 0, BriefingDataService::computeBudgetChars(4096));

        $this->test('empty bodies fit any budget', BriefingDataService::bodiesFitWithHeadroom([], 0));
        $this->test('bodies under budget fit', BriefingDataService::bodiesFitWithHeadroom(['aaaa', 'bbbb'], 100));
        $this->test('bodies over budget do not fit', !BriefingDataService::bodiesFitWithHeadroom(['aaaa', 'bbbb'], 4));
    }

    private function testCalendar(): void
    {
        $now = strtotime('2026-08-27 12:00:00');
        $tasks = [
            ['content' => 'in 3 days', 'due' => ['date' => '2026-08-30']],
            ['content' => 'in 20 days', 'due' => ['date' => '2026-09-16']],
            ['content' => 'earlier today', 'due' => ['datetime' => '2026-08-27 08:00:00']],
            ['content' => 'yesterday', 'due' => ['datetime' => '2026-08-26 08:00:00']],
            ['content' => 'no due', 'due' => null],
            ['content' => 'no due key'],
        ];

        $r = BriefingDataService::partitionCalendar($tasks, $now);
        $this->testEq('upcoming = within 14 days only', ['in 3 days'], array_column($r['upcoming'], 'content'));
        $this->testEq('past-today = earlier today only', ['earlier today'], array_column($r['pastToday'], 'content'));
    }

    private function testPreview(): void
    {
        $email = ['from' => 'Alice <a@x.com>', 'subject' => 'Lunch', 'snippet' => ''];
        $preview = BriefingDataService::buildPreview($email, 'Meet at noon to discuss the quarterly plan');
        $this->test('preview includes sender', strpos($preview, 'Alice') !== false);
        $this->test('preview includes subject', strpos($preview, 'Lunch') !== false);
        $this->test('preview includes body lead', strpos($preview, 'Meet at noon') !== false);

        $email['subject'] = '';
        $preview = BriefingDataService::buildPreview($email, 'Body');
        $this->test('empty subject falls back to (no subject)', strpos($preview, '(no subject)') !== false);
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
