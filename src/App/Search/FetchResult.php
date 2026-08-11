<?php

namespace App\Search;

final readonly class FetchResult
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public string $finalUrl,
        public string $resolvedIp,
        public ?string $contentType,
    ) {}
}
