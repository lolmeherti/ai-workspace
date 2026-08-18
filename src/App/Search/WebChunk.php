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

    /**
     * Reconstruct a chunk from its persisted JSON form (the backing_chunks
     * column). Keys match the promoted constructor properties.
     */
    public static function fromArray(array $a): self
    {
        return new self(
            sourceId:    (string)($a['sourceId'] ?? ''),
            chunkId:     (string)($a['chunkId'] ?? ''),
            url:         (string)($a['url'] ?? ''),
            finalUrl:    (string)($a['finalUrl'] ?? ''),
            title:       (string)($a['title'] ?? ''),
            domain:      (string)($a['domain'] ?? ''),
            publishedAt: $a['publishedAt'] ?? null,
            updatedAt:   $a['updatedAt'] ?? null,
            fetchedAt:   (string)($a['fetchedAt'] ?? ''),
            headingPath: is_array($a['headingPath'] ?? null) ? $a['headingPath'] : [],
            sectionType: (string)($a['sectionType'] ?? 'entity'),
            text:        (string)($a['text'] ?? ''),
            position:    (int)($a['position'] ?? 0),
            entityId:    $a['entityId'] ?? null,
            entityType:  $a['entityType'] ?? null,
            author:      $a['author'] ?? null,
            score:       $a['score'] ?? null,
        );
    }
}
