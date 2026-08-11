<?php

namespace App\Search;

final class CoverageTracker
{
    /** @var array<string, array{sources: array<string, float>}> target => {sources: {sourceId => score}} */
    private array $targets = [];
    private int $attemptedSources = 0;

    private const COVERAGE_THRESHOLD = 0.6;

    private const GENERIC_STOP_WORDS = [
        'specifications', 'specs', 'details', 'info', 'information',
        'latest', 'compare', 'comparison', 'vs', 'versus',
        'review', 'reviews', 'best', 'top', 'recommend',
        'how', 'what', 'when', 'where', 'why', 'which',
        'documentation', 'docs', 'guide', 'tutorial',
    ];

    /**
     * @param array<string> $targets Entities/nouns extracted from the question.
     *   e.g. "What are the iPhone 16 camera and battery specs?"
     *   → ['iphone 16', 'camera', 'battery']
     */
    public function __construct(array $targets)
    {
        foreach ($targets as $target) {
            $normalized = strtolower(trim($target));
            if ($normalized === '' || in_array($normalized, self::GENERIC_STOP_WORDS, true)) {
                continue;
            }
            $this->targets[$normalized] = ['sources' => []];
        }
    }

    /**
     * Extract coverage targets from a user question.
     * Splits on commas, connectors, and extracts significant noun phrases.
     *
     * e.g. "What are the iPhone 16 camera and battery specifications?"
     *   → ['iphone 16', 'camera', 'battery']
     *
     * @return array<string>
     */
    public static function extractTargets(string $question): array
    {
        // Remove question words and common filler
        $cleaned = preg_replace(
            '/\b(what|are|the|is|does|do|can|you|tell|me|about|show|find|search|for|look|up|please|thanks?|thank you)\b/i',
            '', $question
        );

        // Split on 'and', commas, 'or', 'vs', 'versus'
        $parts = preg_split('/\s+(?:and|or|vs\.?|versus)\s+|\s*,\s*/i', $cleaned);

        $targets = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            // Remove trailing filler words
            $part = preg_replace('/\b(specifications?|specs?|details?|info(rmation)?|data|stats?)\s*$/i', '', $part);
            $part = trim($part);

            if (!empty($part) && !in_array(strtolower($part), self::GENERIC_STOP_WORDS, true)) {
                $targets[] = $part;
            }
        }

        // If splitting produced nothing useful, use the whole cleaned question
        if (empty($targets) && !empty($cleaned = trim($cleaned))) {
            $targets[] = $cleaned;
        }

        return $targets;
    }

    /**
     * Update coverage from a chunk that passed BM25 retrieval.
     * Records the supporting source even if not a new best score.
     */
    public function addSupportingSource(string $target, string $sourceId, float $score): void
    {
        $normalized = strtolower(trim($target));
        if (!isset($this->targets[$normalized])) {
            return;
        }

        if ($score >= self::COVERAGE_THRESHOLD) {
            $this->targets[$normalized]['sources'][$sourceId] = $score;
        }
    }

    /**
     * Update coverage from a batch of selected chunks.
     *
     * @param WebChunk[] $selectedChunks
     */
    public function updateFromChunks(array $selectedChunks): void
    {
        foreach ($selectedChunks as $chunk) {
            foreach (array_keys($this->targets) as $target) {
                $score = $this->termMatchScore($target, $chunk->text);
                if ($score >= self::COVERAGE_THRESHOLD) {
                    $this->addSupportingSource($target, $chunk->sourceId, $score);
                }
            }
        }
    }

    /**
     * Check whether ALL required coverage targets are covered by at least
     * one source each.
     */
    public function allRequiredTargetsCovered(): bool
    {
        if (empty($this->targets)) {
            return true;  // No targets to cover — don't block on nothing
        }

        foreach ($this->targets as $target) {
            if (empty($target['sources'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get targets still lacking any coverage.
     *
     * @return array<string>
     */
    public function uncoveredTargets(): array
    {
        $uncovered = [];
        foreach ($this->targets as $name => $data) {
            if (empty($data['sources'])) {
                $uncovered[] = $name;
            }
        }
        return $uncovered;
    }

    /**
     * How many distinct targets have coverage.
     */
    public function coveredCount(): int
    {
        $count = 0;
        foreach ($this->targets as $data) {
            if (!empty($data['sources'])) {
                $count++;
            }
        }
        return $count;
    }

    public function totalTargets(): int
    {
        return count($this->targets);
    }

    /**
     * Decide whether to fetch another candidate based on coverage and policy.
     */
    public function shouldFetchAnother(array $selectedChunks, RetrievalPolicy $policy): bool
    {
        $this->attemptedSources++;
        if ($this->attemptedSources >= $policy->maximumSources) {
            return false;
        }
        if (count($selectedChunks) === 0) {
            return true;  // nothing useful yet
        }

        // Check whether ALL required coverage targets are met
        if (!$this->allRequiredTargetsCovered()) {
            return true;
        }

        // Domain diversity requirement
        return self::countDistinctDomains($selectedChunks) < $policy->minimumEvidenceDomains;
    }

    /**
     * Count distinct domains across selected chunks.
     *
     * @param WebChunk[] $chunks
     */
    private static function countDistinctDomains(array $chunks): int
    {
        $domains = [];
        foreach ($chunks as $chunk) {
            $domains[$chunk->domain] = true;
        }
        return count($domains);
    }

    /**
     * Simple term match: checks if the target text appears in the chunk,
     * with a fuzzy-ish score. Case-insensitive substring match.
     */
    private function termMatchScore(string $target, string $chunkText): float
    {
        $lowerText = strtolower($chunkText);
        $lowerTarget = strtolower($target);

        if (str_contains($lowerText, $lowerTarget)) {
            // Score inversely proportional to target length relative to chunk
            // Short targets matching exactly get a boost
            $ratio = strlen($lowerTarget) / max(strlen($lowerText), 1);
            return min(1.0, 0.7 + (1.0 - $ratio) * 0.3);
        }

        // Partial match: check individual words
        $targetWords = preg_split('/\s+/', $lowerTarget);
        $matched = 0;
        foreach ($targetWords as $word) {
            if (strlen($word) > 2 && str_contains($lowerText, $word)) {
                $matched++;
            }
        }

        if ($matched === 0 || count($targetWords) === 0) {
            return 0.0;
        }

        return ($matched / count($targetWords)) * 0.5;
    }
}
