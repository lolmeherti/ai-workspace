<?php

namespace App\Services;

class TagParserService
{
    private const TAG_MAP = [
        'web' => 'web_search',
        'files' => 'search_files',
        'memory' => 'search_memories',
        'local' => 'local_search',
    ];

    public function parse(string $message): array
    {
        $tags = [];
        $displayTags = [];
        $remaining = ltrim($message);

        while ($remaining !== '') {
            if (!preg_match('/^@(\\w+)\\b/', $remaining, $matches)) {
                break;
            }

            $tag = strtolower($matches[1]);

            if (!isset(self::TAG_MAP[$tag])) {
                break;
            }

            $tags[] = self::TAG_MAP[$tag];
            $displayTags[] = $tag;
            $remaining = substr($remaining, strlen($matches[0]));
            $remaining = ltrim($remaining);
        }

        return [
            'tags' => $tags,
            'displayTags' => $displayTags,
            'query' => trim($remaining),
        ];
    }
}
