<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\PromptAssemblyService;

class MessageAssemblyTest
{
    private PromptAssemblyService $prompt;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private ?string $origWindow = null;

    public function __construct(\App\Database $db, string $uploadDir)
    {
        $this->prompt = new PromptAssemblyService($db, $uploadDir);
    }

    public function run(): bool
    {
        $this->runPreprocessHistory();
        $this->runBuildMessagesArray();
        $this->runStableSystemPrompt();

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
            $this->failures[] = ['label' => $label, 'condition' => false];
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

    // ===================================================================
    // preprocessHistory — filters tool_call and super_abilities stubs
    // ===================================================================
    private function runPreprocessHistory(): void
    {
        echo "\n=== preprocessHistory ===\n";

        $input = [
            ['role' => 'user', 'message' => 'find my cv'],
            ['role' => 'assistant', 'message' => 'search_files QUERY:cv', 'message_type' => 'tool_call'],
            ['role' => 'system', 'message' => 'Found: CV_2024.pdf', 'message_type' => 'data_fetching'],
            ['role' => 'assistant', 'message' => 'I found your CV.', 'message_type' => 'text'],
            ['role' => 'assistant', 'message' => 'super_abilities', 'message_type' => 'super_abilities'],
        ];

        $output = $this->prompt->preprocessHistory($input);

        $this->testEq('filters tool_call message', 3, count($output));
        $this->test('user message preserved',
            $output[0]['role'] === 'user' && $output[0]['message'] === 'find my cv');
        $this->test('system data_fetching preserved',
            $output[1]['role'] === 'system' && $output[1]['message'] === 'Found: CV_2024.pdf');
        $this->test('assistant text preserved',
            $output[2]['role'] === 'assistant' && $output[2]['message'] === 'I found your CV.');
        $this->test('tool_call row is absent',
            !in_array('search_files QUERY:cv', array_column($output, 'message')));
        $this->test('super_abilities row is absent',
            !in_array('super_abilities', array_column($output, 'message')));

        // All normal messages — nothing filtered
        $normal = [
            ['role' => 'user', 'message' => 'hello'],
            ['role' => 'assistant', 'message' => 'hi there'],
            ['role' => 'user', 'message' => 'how are you'],
            ['role' => 'assistant', 'message' => 'great thanks'],
        ];
        $this->testEq('normal messages all pass through', 4,
            count($this->prompt->preprocessHistory($normal)));

        // Only tool_calls — everything filtered
        $onlyTools = [
            ['role' => 'assistant', 'message' => 'call1', 'message_type' => 'tool_call'],
            ['role' => 'assistant', 'message' => 'call2', 'message_type' => 'tool_call'],
        ];
        $this->testEq('only tool_calls — all filtered', 0,
            count($this->prompt->preprocessHistory($onlyTools)));

        // Missing message_type key — treated as normal, passes through
        $noType = [
            ['role' => 'user', 'message' => 'test'],
            ['role' => 'assistant', 'message' => 'reply'],
        ];
        $this->testEq('missing message_type key — passes through', 2,
            count($this->prompt->preprocessHistory($noType)));

        // tool_call with role=user — should NOT be filtered (only filters role=assistant)
        $userToolCall = [
            ['role' => 'user', 'message' => 'system output', 'message_type' => 'tool_call'],
        ];
        $this->testEq('tool_call with role=user — not filtered (only assistant)', 1,
            count($this->prompt->preprocessHistory($userToolCall)));
    }

    // ===================================================================
    // buildMessagesArray — Context Data exempt from rolling window
    // ===================================================================
    private function runBuildMessagesArray(): void
    {
        echo "\n=== buildMessagesArray ===\n";

        $this->origWindow = $_ENV['CHAT_ROLLING_WINDOW_LIMIT'] ?? null;
        $_ENV['CHAT_ROLLING_WINDOW_LIMIT'] = '15';

        $sys = 'SYSTEM_PROMPT';

        $history = [['role' => 'system', 'message' => 'FACT_SENTINEL_AAA', 'message_type' => 'data_fetching']];
        for ($i = 0; $i < 20; $i++) {
            $history[] = ['role' => ($i % 2 === 0 ? 'user' : 'assistant'), 'message' => "conv {$i}", 'message_type' => 'text'];
        }
        $out = $this->prompt->buildMessagesArray($sys, $history);
        $this->testEq('fact survives >15 messages: appears exactly once', 1, $this->countContent($out, 'FACT_SENTINEL_AAA'));

        $history2 = [];
        for ($i = 0; $i < 3; $i++) {
            $history2[] = ['role' => 'user', 'message' => "q {$i}", 'message_type' => 'text'];
        }
        $history2[] = ['role' => 'system', 'message' => 'RECENT_SENTINEL_BBB', 'message_type' => 'data_fetching'];
        $out2 = $this->prompt->buildMessagesArray($sys, $history2);
        $this->testEq('exactly-once: recent data_fetching not doubled', 1, $this->countContent($out2, 'RECENT_SENTINEL_BBB'));

        $history3 = [
            ['role' => 'system', 'message' => 'FIRST_SENTINEL', 'message_type' => 'data_fetching'],
            ['role' => 'user', 'message' => 'hello', 'message_type' => 'text'],
            ['role' => 'system', 'message' => 'SECOND_SENTINEL', 'message_type' => 'data_fetching'],
        ];
        $out3 = $this->prompt->buildMessagesArray($sys, $history3);
        $posFirst = $this->findContentPos($out3, 'FIRST_SENTINEL');
        $posSecond = $this->findContentPos($out3, 'SECOND_SENTINEL');
        $this->test('ordering preserved: first block before second', $posFirst !== -1 && $posSecond !== -1 && $posFirst < $posSecond);

        $posHello = $this->findContentPos($out3, 'hello');
        // A block rides with the turn that produced it. FIRST_SENTINEL precedes
        // the only conversation row in this fixture, so its turn is older than
        // the whole window and it keeps that place, ahead of the window;
        // SECOND_SENTINEL follows the turn that produced it.
        $this->test('evidence older than the window keeps its chronological place (ahead of the window)',
            $posFirst !== -1 && $posHello !== -1 && $posFirst < $posHello);
        $this->test('evidence whose turn is inside the window follows that turn',
            $posSecond !== -1 && $posHello !== -1 && $posSecond > $posHello);

        // The point of the placement: a later turn's array must be a strict
        // extension of the previous turn's, so the engine extends the sequence it
        // still holds instead of re-reading the evidence on every turn.
        $turnN = [
            ['role' => 'user', 'message' => 'q1'],
            ['role' => 'system', 'message' => 'EVIDENCE_ONE', 'message_type' => 'data_fetching'],
            ['role' => 'assistant', 'message' => 'a1'],
            ['role' => 'user', 'message' => 'q2'],
        ];
        $turnN1 = array_merge($turnN, [
            ['role' => 'assistant', 'message' => 'a2'],
            ['role' => 'user', 'message' => 'q3'],
            ['role' => 'system', 'message' => 'EVIDENCE_TWO', 'message_type' => 'data_fetching'],
        ]);
        $arrN = $this->prompt->buildMessagesArray($sys, $turnN);
        $arrN1 = $this->prompt->buildMessagesArray($sys, $turnN1);
        $extends = count($arrN1) > count($arrN);
        for ($i = 0; $extends && $i < count($arrN); $i++) {
            $extends = json_encode($arrN[$i]) === json_encode($arrN1[$i]);
        }
        $this->test('later turn strictly extends the previous array (evidence not re-anchored)', $extends);
        $this->test('fresh tool results stay last, after the current user turn',
            $this->findContentPos($arrN1, 'EVIDENCE_TWO') === count($arrN1) - 1);

        // The repeated-request reminder is stored on the evidence row it followed,
        // so it must be re-emitted in place on every later turn. Appending it to
        // the current turn's array only would make the next turn diverge there and
        // cost the whole cached prefix behind it.
        $turnR = [
            ['role' => 'user', 'message' => 'q1'],
            ['role' => 'system', 'message' => 'EVIDENCE_R', 'message_type' => 'data_fetching', 'turn_reminder' => 'REMINDER_SENTINEL'],
            ['role' => 'assistant', 'message' => 'a1'],
            ['role' => 'user', 'message' => 'q2'],
        ];
        $arrR = $this->prompt->buildMessagesArray($sys, $turnR);
        $posBlock = $this->findContentPos($arrR, 'EVIDENCE_R');
        $posReminder = $this->findContentPos($arrR, 'REMINDER_SENTINEL');
        $this->test('stored reminder is emitted directly after its evidence block',
            $posBlock !== -1 && $posReminder === $posBlock + 1);

        $arrR1 = $this->prompt->buildMessagesArray($sys, array_merge($turnR, [
            ['role' => 'assistant', 'message' => 'a2'],
            ['role' => 'user', 'message' => 'q3'],
        ]));
        $extendsR = count($arrR1) > count($arrR);
        for ($i = 0; $extendsR && $i < count($arrR); $i++) {
            $extendsR = json_encode($arrR[$i]) === json_encode($arrR1[$i]);
        }
        $this->test('a turn carrying a reminder still extends the next turn (append-only)', $extendsR);

        $roles = array_values(array_unique(array_map(fn($m) => $m['role'], $out3)));
        $this->test('no new roles', empty(array_diff($roles, ['system', 'user', 'assistant', 'tool'])));

        $history4 = [
            ['role' => 'system', 'message' => 'ACTIVE_SENTINEL', 'message_type' => 'data_fetching', 'raw_evicted' => 0],
            ['role' => 'system', 'message' => 'EVICTED_SENTINEL', 'message_type' => 'data_fetching', 'raw_evicted' => 1],
            ['role' => 'user', 'message' => 'hello', 'message_type' => 'text'],
        ];
        $out4 = $this->prompt->buildMessagesArray($sys, $history4);
        $this->testEq('active data_fetching injected', 1, $this->countContent($out4, 'ACTIVE_SENTINEL'));
        $this->testEq('evicted data_fetching excluded', 0, $this->countContent($out4, 'EVICTED_SENTINEL'));

        if ($this->origWindow === null) {
            unset($_ENV['CHAT_ROLLING_WINDOW_LIMIT']);
        } else {
            $_ENV['CHAT_ROLLING_WINDOW_LIMIT'] = $this->origWindow;
        }
    }

    // ===================================================================
    // stable system prompt + runtime timestamp (cheap-fix regression)
    // ===================================================================
    private function runStableSystemPrompt(): void
    {
        echo "\n=== stable system prompt + runtime timestamp ===\n";

        $a = $this->prompt->buildSystemPrompt(false);
        $b = $this->prompt->buildSystemPrompt(false);
        $this->test('buildSystemPrompt is byte-stable across calls', $a === $b);
        $this->test('buildSystemPrompt has no date/time line', !str_contains($a, "Today's date"));
        $this->test('buildSystemPrompt carries runtime-timestamp statement',
            str_contains($a, 'current time is supplied to you as a runtime timestamp'));
        $this->test('buildSystemPrompt carries untrusted-data rule',
            str_contains($a, 'untrusted reference material'));

        $line = $this->prompt->currentTimeContextLine();
        $this->test('currentTimeContextLine carries a five-minute bucket (not a second-accurate stamp)',
            (bool)preg_match('/^current_time = \d{4}-\d{2}-\d{2} \d{2}:[0-5][05]\n$/', $line));

        // The bucket is persisted with the user turn and re-emitted by every later
        // prompt, so it has to be stable inside its window and change only on the
        // next one: a value that drifted per call is what cost the prefix cache.
        $t = time();
        $left = 300 - ($t % 300);
        $this->test('timeBucket is stable to the last second of its window',
            \App\Services\PromptAssemblyService::timeBucket($t + $left - 1) === \App\Services\PromptAssemblyService::timeBucket($t));
        $this->test('timeBucket changes on the next window',
            \App\Services\PromptAssemblyService::timeBucket($t + $left) !== \App\Services\PromptAssemblyService::timeBucket($t));

        // System message identical with and without evidence (no guard mutation).
        $sys = 'SYS';
        $withEvidence = [
            ['role' => 'system', 'message' => 'EV1', 'message_type' => 'data_fetching'],
            ['role' => 'user', 'message' => 'hi', 'message_type' => 'text'],
        ];
        $noEvidence = [
            ['role' => 'user', 'message' => 'hi', 'message_type' => 'text'],
        ];
        $m1 = $this->prompt->buildMessagesArray($sys, $withEvidence);
        $m2 = $this->prompt->buildMessagesArray($sys, $noEvidence);
        $this->test('system message identical with/without evidence',
            $m1[0]['content'] === $m2[0]['content'] && $m1[0]['content'] === $sys);

        // current_time appended as a trailing message, not in the system prompt
        // or the user turn (so it can't bust cross-turn prefix reuse).
        $t = "current_time = 2026-09-22T19:00:00+02:00\n";
        $m3 = $this->prompt->buildMessagesArray($sys, $noEvidence, [], $t);
        $this->test('current_time not in system prompt', !str_contains($m3[0]['content'], 'current_time ='));
        $this->test('current_time not prepended to the user turn',
            isset($m3[1]) && is_string($m3[1]['content']) && !str_starts_with($m3[1]['content'], 'current_time ='));
        $last = end($m3);
        $this->test('current_time appended as the trailing message',
            is_array($last) && ($last['role'] ?? null) === 'user' && ($last['content'] ?? '') === $t);

        // Evidence wrapped with valid_sources + fetched_at when present.
        $ev = [
            ['role' => 'system', 'message' => '<source id="S1">x</source>', 'message_type' => 'data_fetching', 'created_at' => '2026-09-22 19:00:00'],
            ['role' => 'user', 'message' => 'hi', 'message_type' => 'text'],
        ];
        $m4 = $this->prompt->buildMessagesArray($sys, $ev);
        $evidenceBlock = null;
        foreach ($m4 as $msg) {
            if (is_string($msg['content']) && str_contains($msg['content'], 'valid_sources="S1"')) {
                $evidenceBlock = $msg['content'];
            }
        }
        $this->test('evidence carries valid_sources', $evidenceBlock !== null);
        $this->test('evidence carries fetched_at',
            $evidenceBlock !== null && str_contains($evidenceBlock, 'fetched_at="2026-09-22 19:00:00"'));
    }

    private function countContent(array $messages, string $needle): int
    {
        $count = 0;
        foreach ($messages as $m) {
            $c = $m['content'] ?? '';
            if (is_string($c) && str_contains($c, $needle)) {
                $count++;
            }
        }
        return $count;
    }

    private function findContentPos(array $messages, string $needle): int
    {
        foreach ($messages as $i => $m) {
            $c = $m['content'] ?? '';
            if (is_string($c) && str_contains($c, $needle)) {
                return $i;
            }
        }
        return -1;
    }
}
