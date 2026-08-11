<?php

namespace App\Search;

class ExtractiveCompressor
{
    private array $queryTokens;

    public function __construct(string $query)
    {
        $this->queryTokens = $this->tokenize($query);
    }

    /**
     * Shrink chunks deterministically to fit a token budget.
     * Returns shrunk versions. Never invokes an LLM.
     *
     * @param WebChunk[] $chunks
     * @return array{array<string,string>, int} [texts keyed by chunkId, tokens saved]
     */
    public function compress(array $chunks, TokenCounter $counter, int $targetTokens, int $currentTokens): array
    {
        if ($currentTokens <= $targetTokens || empty($chunks)) {
            $texts = [];
            foreach ($chunks as $c) $texts[$c->chunkId] = $c->text;
            return [$texts, 0];
        }

        $needToSave = $currentTokens - $targetTokens;
        $saved = 0;
        $texts = [];

        foreach ($chunks as $chunk) {
            $texts[$chunk->chunkId] = match ($chunk->sectionType) {
                'table'     => $this->compressTable($chunk, $counter),
                'list'      => $this->compressList($chunk, $counter),
                'code'      => $chunk->text,
                'paragraph' => $this->compressProse($chunk, $counter),
                default     => $this->compressProse($chunk, $counter),
            };
            $saved += $counter->count($chunk->text) - $counter->count($texts[$chunk->chunkId]);
            if ($saved >= $needToSave) break;
        }

        return [$texts, $saved];
    }

    private function compressProse(WebChunk $chunk, TokenCounter $counter): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $chunk->text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) <= 3) return $chunk->text;

        $scored = [];
        foreach ($sentences as $i => $s) {
            $terms = $this->tokenize($s);
            $overlap = count(array_intersect($this->queryTokens, $terms));
            $scored[] = ['idx' => $i, 'text' => $s, 'score' => $overlap];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $keepCount = max(3, (int)(count($sentences) * 0.5));

        $keep = [];
        foreach (array_slice($scored, 0, $keepCount) as $s) {
            $keep[$s['idx']] = $s['text'];
        }

        // Add 1 adjacent sentence for context
        foreach (array_keys($keep) as $idx) {
            if ($idx > 0 && !isset($keep[$idx - 1])) {
                $keep[$idx - 1] = $sentences[$idx - 1];
            }
            if ($idx < count($sentences) - 1 && !isset($keep[$idx + 1])) {
                $keep[$idx + 1] = $sentences[$idx + 1];
            }
        }

        ksort($keep);
        return implode(' ', $keep);
    }

    private function compressTable(WebChunk $chunk, TokenCounter $counter): string
    {
        $lines = explode("\n", trim($chunk->text));
        if (count($lines) <= 4) return $chunk->text;

        $header = $lines[0];
        $sep = ($lines[1] ?? '');

        $rows = array_slice($lines, 2);
        $keepRows = [];

        foreach ($rows as $row) {
            $terms = $this->tokenize($row);
            if (!empty(array_intersect($this->queryTokens, $terms))) {
                $keepRows[] = $row;
            }
        }

        if (empty($keepRows)) {
            $keepRows = array_slice($rows, 0, min(5, count($rows)));
        }

        return implode("\n", array_merge([$header, $sep], $keepRows));
    }

    private function compressList(WebChunk $chunk, TokenCounter $counter): string
    {
        $items = preg_split('/\n(?=[-*+]|\d+\.)/', $chunk->text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($items) <= 3) return $chunk->text;

        $scored = [];
        foreach ($items as $i => $item) {
            $terms = $this->tokenize($item);
            $overlap = count(array_intersect($this->queryTokens, $terms));
            $scored[] = ['idx' => $i, 'text' => $item, 'score' => $overlap];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $keepCount = max(2, (int)(count($items) * 0.5));

        $keep = [];
        foreach (array_slice($scored, 0, $keepCount) as $s) {
            $keep[$s['idx']] = $s['text'];
        }
        ksort($keep);
        return implode("\n", $keep);
    }

    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $words = explode(' ', trim($text));
        return array_values(array_filter($words, fn($w) => strlen($w) >= 2));
    }
}
