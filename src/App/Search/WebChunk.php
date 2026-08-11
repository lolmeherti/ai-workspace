<?php

namespace App\Search;

final class WebChunk
{
    public function __construct(
        public string $sourceId,
        public string $chunkId,
        public string $url,
        public string $finalUrl,
        public string $title,
        public string $domain,
        public ?string $publishedAt,
        public ?string $updatedAt,
        public string $fetchedAt,
        public array $headingPath,
        public string $sectionType,
        public string $text,
        public int $position,
        /** Bridge entity provenance — null for snippet-mode chunks. */
        public ?string $entityId = null,
        public ?string $entityType = null,
        public ?string $author = null,
        public ?int $score = null,
    ) {}
}
