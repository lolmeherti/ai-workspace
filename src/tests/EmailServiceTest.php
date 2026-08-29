<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\EmailService;

/**
 * EmailService error classification — deterministic (pure static logic).
 *
 * Verifies the fix for the recurring "failed to authenticate" false alarm:
 * webklex/php-imap wraps a socket read timeout as
 * AuthFailedException("failed to authenticate") and hides the real
 * "empty response" in getPrevious(). The classifier must walk the chain and
 * NOT mislabel a transient connection failure as AUTH_FAILED (which tells the
 * user "check your credentials" and blocks the retry).
 */
class EmailServiceTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== classifyImapError ===\n";
        $this->testClassify();

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

    private function testClassify(): void
    {
        // 1. Genuine credential rejection — the only true AUTH_FAILED case.
        $genuine = new \Exception('NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)');
        $this->assertClassify('genuine auth -> AUTH_FAILED, not transient', $genuine, 'AUTH_FAILED', false);

        // 2. Login-phase socket timeout: webklex wraps "empty response" as
        //    AuthFailedException("failed to authenticate").
        $wrappedTimeout = new \Exception('failed to authenticate', 0, new \Exception('empty response'));
        $this->assertClassify('wrapped timeout -> IMAP_ERROR, transient (NOT AUTH_FAILED)', $wrappedTimeout, 'IMAP_ERROR', true);

        // 3. Direct connection timeout.
        $timeout = new \Exception('connection timed out');
        $this->assertClassify('connection timeout -> CONNECTION_TIMEOUT, transient', $timeout, 'CONNECTION_TIMEOUT', true);

        // 4. Connect-phase failure wrapping a timeout.
        $wrappedConnect = new \Exception('connection setup failed', 0, new \Exception('Connection timed out'));
        $this->assertClassify('wrapped connect timeout -> CONNECTION_TIMEOUT, transient', $wrappedConnect, 'CONNECTION_TIMEOUT', true);

        // 5. Yahoo-style transient throttle -> retried, not AUTH_FAILED.
        $throttle = new \Exception('NO [UNAVAILABLE] LOGIN Temporary server error. Please try again later');
        $this->assertClassify('provider throttle -> transient', $throttle, 'IMAP_ERROR', true);

        // 6. Plain IMAP error (no transient/auth needles).
        $plain = new \Exception("folder doesn't exist");
        $this->assertClassify('plain imap error -> IMAP_ERROR, not transient', $plain, 'IMAP_ERROR', false);

        // 7. Auth rejection that ALSO says "try again later" must NOT be
        //    retried (retrying bad credentials can lock the account).
        $mixed = new \Exception('NO [AUTHENTICATIONFAILED] Invalid credentials. Try again later.');
        $this->assertClassify('auth wins over transient wording -> not retried', $mixed, 'AUTH_FAILED', false);

        // 8. Friendly detail for the timeout case (not the raw "empty response").
        $detail = EmailService::classifyImapError($wrappedTimeout)['detail'];
        $this->test('wrapped timeout detail is human-friendly', str_contains($detail, 'dropped the connection'));
    }

    private function assertClassify(string $label, \Throwable $e, string $expectedType, bool $expectedTransient): void
    {
        $r = EmailService::classifyImapError($e);
        $ok = $r['type'] === $expectedType && $r['transient'] === $expectedTransient;
        printf("  [%s] %s (type=%s, transient=%s)\n", $ok ? 'PASS' : 'FAIL', $label, $r['type'], $r['transient'] ? 'true' : 'false');
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => "$expectedType/" . ($expectedTransient ? 'true' : 'false'), 'actual' => "{$r['type']}/" . ($r['transient'] ? 'true' : 'false')];
            $this->failed++;
        } else {
            $this->passed++;
        }
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
}
