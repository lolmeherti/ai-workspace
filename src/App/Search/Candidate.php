<?php

namespace App\Search;

final class Candidate
{
    public float $score = 0.0;

    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $snippet,
        public readonly string $domain,
        public readonly int $position,
        public readonly ?string $engine = null,
        public readonly ?string $publishedDate = null,
    ) {}
}
