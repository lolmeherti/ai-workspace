<?php

namespace App\Search;

final class BridgeFetchResult
{
    public function __construct(
        public readonly string $status,
        /** @var array{url?: string, title?: string, domain?: string, entities?: array<int, array{entity_id?: string, entity_type?: string, author?: string, score?: int, body?: string, published?: string, canonical_url?: string, parent_id?: string}>}|null */
        public readonly ?array $content,
        public readonly ?string $error,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === 'success' && $this->content !== null;
    }
}
