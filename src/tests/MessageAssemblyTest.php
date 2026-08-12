<?php

declare(strict_types=1);

namespace App\Tests;

use App\ChatManager;
use App\Services\PromptAssemblyService;

class MessageAssemblyTest
{
    private PromptAssemblyService $prompt;
    private ChatManager $chat;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db, \App\AgentManager $agent, string $uploadDir)
    {
        $this->prompt = new PromptAssemblyService($db, $uploadDir);
        $this->chat = new ChatManager($db, $agent);
    }

    public function run(): bool
    {
        $this->runPreprocessHistory();
        $this->runCleanMessagesArray();

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

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
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
        $this->test('system data_fetching preserved (converted later by cleanMessagesArray)',
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
    // cleanMessagesArray — converts system→user for non-first positions
    // ===================================================================
    private function runCleanMessagesArray(): void
    {
        echo "\n=== cleanMessagesArray ===\n";

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'find my cv'],
            ['role' => 'system', 'content' => 'Tool result: CV_2024.pdf found.'],
            ['role' => 'assistant', 'content' => 'I found your CV.'],
            ['role' => 'user', 'content' => 'thanks'],
        ];

        $cleaned = $this->invokePrivate($this->chat, 'cleanMessagesArray', $messages);

        $this->test('position 0 system stays system',
            $cleaned[0]['role'] === 'system');
        $this->test('position 0 system content unchanged',
            $cleaned[0]['content'] === 'You are a helpful assistant.');
        $this->test('position 1 user stays user',
            $cleaned[1]['role'] === 'user');
        $this->test('position 2 system converted to user',
            $cleaned[2]['role'] === 'user');
        $this->test('position 2 system gets [System Context / Tool Output] prefix',
            str_starts_with($cleaned[2]['content'], '[System Context / Tool Output]:'));
        $this->test('position 2 original content preserved after prefix',
            str_contains($cleaned[2]['content'], 'CV_2024.pdf found.'));
        $this->test('position 3 assistant unchanged',
            $cleaned[3]['role'] === 'assistant' && $cleaned[3]['content'] === 'I found your CV.');
        $this->test('position 4 user unchanged',
            $cleaned[4]['role'] === 'user' && $cleaned[4]['content'] === 'thanks');

        // Single system message only
        $single = [['role' => 'system', 'content' => 'System prompt only.']];
        $cleanedSingle = $this->invokePrivate($this->chat, 'cleanMessagesArray', $single);
        $this->test('single system message stays system (position 0)',
            $cleanedSingle[0]['role'] === 'system');

        // All user messages — no conversions
        $allUser = [
            ['role' => 'user', 'content' => 'msg1'],
            ['role' => 'user', 'content' => 'msg2'],
        ];
        $cleanedAllUser = $this->invokePrivate($this->chat, 'cleanMessagesArray', $allUser);
        $this->test('all-user: no role changes', !array_filter($cleanedAllUser, fn($m) => $m['role'] !== 'user'));

        // Multiple systems after position 0 — all converted
        $multiSys = [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'system', 'content' => 'tool result 1'],
            ['role' => 'system', 'content' => 'tool result 2'],
            ['role' => 'user', 'content' => 'user query'],
        ];
        $cleanedMultiSys = $this->invokePrivate($this->chat, 'cleanMessagesArray', $multiSys);
        $this->test('multi-system: position 0 stays system',
            $cleanedMultiSys[0]['role'] === 'system');
        $this->test('multi-system: position 1 converted to user',
            $cleanedMultiSys[1]['role'] === 'user');
        $this->test('multi-system: position 2 converted to user',
            $cleanedMultiSys[2]['role'] === 'user');
        $this->test('multi-system: position 1 has prefix',
            str_starts_with($cleanedMultiSys[1]['content'], '[System Context / Tool Output]:'));
        $this->test('multi-system: position 2 has prefix',
            str_starts_with($cleanedMultiSys[2]['content'], '[System Context / Tool Output]:'));

        // Message with missing role key
        $badMsg = [
            ['role' => 'system', 'content' => 'prompt'],
            ['content' => 'no role key'],  // missing role
        ];
        $cleanedBad = $this->invokePrivate($this->chat, 'cleanMessagesArray', $badMsg);
        $this->test('missing role key: does not crash', isset($cleanedBad[1]));

        // Empty array
        $this->testEq('empty array: returns empty', [],
            $this->invokePrivate($this->chat, 'cleanMessagesArray', []));
    }
}
