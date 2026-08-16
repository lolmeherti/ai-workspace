<?php

namespace App\Search;

final class FileChunk
{
    public function __construct(
        public int $fileId,
        public string $title,
        public array $entities,
        public string $text,
        public int $position,
    ) {}
}
