<?php

namespace App\Search;

class EvidenceBuilder
{
    /**
     * @param WebChunk[] $chunks
     */
    public function build(array $chunks): string
    {
        if (empty($chunks)) return '';

        $grouped = [];
        foreach ($chunks as $chunk) {
            $grouped[$chunk->sourceId][] = $chunk;
        }

        $lines = [];

        foreach ($grouped as $sourceId => $sourceChunks) {
            $first = $sourceChunks[0];

            $lines[] = "<source id=\"{$sourceId}\">";
            $lines[] = '<title>';
            $lines[] = htmlspecialchars($first->title ?: 'Untitled', ENT_XML1);
            $lines[] = '</title>';
            $lines[] = "<domain>{$first->domain}</domain>";

            if ($first->publishedAt) {
                $lines[] = '<published>' . htmlspecialchars($first->publishedAt, ENT_XML1) . '</published>';
            }
            $lines[] = '<fetched>' . htmlspecialchars($first->fetchedAt, ENT_XML1) . '</fetched>';

            self::appendEntityMeta($first, $lines);

            foreach ($sourceChunks as $chunk) {
                $heading = !empty($chunk->headingPath)
                    ? ' section="' . htmlspecialchars(implode(' > ', $chunk->headingPath), ENT_XML1) . '"'
                    : '';

                $lines[] = "<chunk id=\"{$chunk->chunkId}\"{$heading}>";
                $lines[] = htmlspecialchars($chunk->text, ENT_XML1);
                $lines[] = '</chunk>';
            }

            $lines[] = '</source>';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build evidence using pre-compressed texts keyed by chunkId.
     *
     * @param WebChunk[] $chunks
     * @param array<string,string> $texts chunkId => compressed text
     */
    public function buildTexts(array $chunks, array $texts): string
    {
        if (empty($chunks)) return '';

        $grouped = [];
        foreach ($chunks as $chunk) {
            $grouped[$chunk->sourceId][] = $chunk;
        }

        $lines = [];

        foreach ($grouped as $sourceId => $sourceChunks) {
            $first = $sourceChunks[0];

            $lines[] = "<source id=\"{$sourceId}\">";
            $lines[] = '<title>';
            $lines[] = htmlspecialchars($first->title ?: 'Untitled', ENT_XML1);
            $lines[] = '</title>';
            $lines[] = "<domain>{$first->domain}</domain>";

            if ($first->publishedAt) {
                $lines[] = '<published>' . htmlspecialchars($first->publishedAt, ENT_XML1) . '</published>';
            }
            $lines[] = '<fetched>' . htmlspecialchars($first->fetchedAt, ENT_XML1) . '</fetched>';

            self::appendEntityMeta($first, $lines);

            foreach ($sourceChunks as $chunk) {
                $heading = !empty($chunk->headingPath)
                    ? ' section="' . htmlspecialchars(implode(' > ', $chunk->headingPath), ENT_XML1) . '"'
                    : '';

                $text = $texts[$chunk->chunkId] ?? $chunk->text;

                $lines[] = "<chunk id=\"{$chunk->chunkId}\"{$heading}>";
                $lines[] = htmlspecialchars($text, ENT_XML1);
                $lines[] = '</chunk>';
            }

            $lines[] = '</source>';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Append entity-type, author, and score metadata when available.
     * @param string[] $lines
     */
    private static function appendEntityMeta(WebChunk $first, array &$lines): void
    {
        if ($first->entityType !== null) {
            $lines[] = '<entity_type>' . htmlspecialchars($first->entityType, ENT_XML1) . '</entity_type>';
        }
        if ($first->author !== null) {
            $lines[] = '<author>' . htmlspecialchars($first->author, ENT_XML1) . '</author>';
        }
        if ($first->score !== null) {
            $lines[] = '<score>' . $first->score . '</score>';
        }
    }

    public function estimateTokens(string $evidenceBlock): int
    {
        return (int)(mb_strlen($evidenceBlock) / 4);
    }
}
