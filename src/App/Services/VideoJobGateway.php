<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The Go launcher's watch endpoints, over the same local relay BridgeFetcher already uses.
 *
 * The client is deliberately dumb: start / poll / cancel, each returning a plain array, so the
 * polling policy lives in VideoWatchRunner where it can be tested without a launcher on the machine.
 */
interface VideoJobGateway
{
    /** @return array{ok:bool, job:array<string,mixed>, error:string} */
    public function start(string $url): array;

    /** @return array{ok:bool, job:array<string,mixed>, error:string} */
    public function poll(string $jobId): array;

    /** @return array{ok:bool, error:string} */
    public function cancel(string $jobId): array;
}
