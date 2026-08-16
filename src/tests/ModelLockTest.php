<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\ModelLock;
use App\Services\ModelBusyException;

/**
 * Deterministic ModelLock tests against the REAL Redis instance (ai_redis).
 * Exercises the atomic SET NX PX / Lua compare-and-delete primitives, reentrancy
 * via the process-local owner token, renew-returns-0 abort, and token-bound status.
 */
class ModelLockTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        try {
            $this->runAcquireReleaseLifecycle();
            $this->runMutualExclusion();
            $this->runRenewSemantics();
            $this->runTokenGuardedRelease();
            $this->runTokenBoundStatus();
        } finally {
            ModelLock::release(ModelLock::ownerToken() ?? '');
        }

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

    private function runAcquireReleaseLifecycle(): void
    {
        echo "\n=== acquire / release lifecycle ===\n";

        $token = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->test('acquire returns a token', $token !== null);
        $this->test('acquire sets owner', ModelLock::isOwner());
        $this->testEq('ownerToken matches returned token', $token, ModelLock::ownerToken());

        ModelLock::release((string)$token);
        $this->test('release clears owner', !ModelLock::isOwner());
        $this->testEq('release nulls ownerToken', null, ModelLock::ownerToken());

        $token2 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->test('re-acquire after release succeeds', $token2 !== null);
        ModelLock::release((string)$token2);
    }

    private function runMutualExclusion(): void
    {
        echo "\n=== mutual exclusion ===\n";

        $t1 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $t2 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->testEq('second acquire while held returns null', null, $t2);

        $threw = false;
        try {
            ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
        } catch (ModelBusyException) {
            $threw = true;
        }
        $this->test('acquireOrBusy throws ModelBusyException while held', $threw);

        ModelLock::release((string)$t1);

        $t3 = ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
        $this->test('acquireOrBusy succeeds after release', $t3 !== '');
        ModelLock::release($t3);
    }

    private function runRenewSemantics(): void
    {
        echo "\n=== renew semantics ===\n";

        $t1 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->test('renew with correct token returns true', ModelLock::renew((string)$t1, 2000));
        $this->test('renew with wrong token returns false', !ModelLock::renew('wrong-token', 2000));
        ModelLock::release((string)$t1);

        $this->test('renew after release returns false (ownership lost)', !ModelLock::renew((string)$t1, 2000));
    }

    private function runTokenGuardedRelease(): void
    {
        echo "\n=== token-guarded release ===\n";

        $t1 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);

        ModelLock::release('bogus-token');
        $still = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->testEq('release with wrong token does not free the lock', null, $still);

        ModelLock::release((string)$t1);
        $after = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->test('release with correct token frees the lock', $after !== null);
        ModelLock::release((string)$after);
    }

    private function runTokenBoundStatus(): void
    {
        echo "\n=== token-bound status ===\n";

        $t1 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        ModelLock::setStatus((string)$t1, 'Indexing files: 12 / 30');
        $this->testEq('busyMessage reads current owner status', 'Indexing files: 12 / 30', ModelLock::busyMessage());

        $msg = '';
        try {
            ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
        } catch (ModelBusyException $e) {
            $msg = $e->getMessage();
        }
        $this->testEq('busy exception carries owner status message', 'Indexing files: 12 / 30', $msg);

        ModelLock::release((string)$t1);

        $t2 = ModelLock::acquire(ModelLock::DEFAULT_TTL_MS);
        $this->test('new owner does not see stale owner status', ModelLock::busyMessage() !== 'Indexing files: 12 / 30');
        ModelLock::release((string)$t2);
    }
}
