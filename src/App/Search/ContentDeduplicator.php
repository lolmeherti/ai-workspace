<?php

namespace App\Search;

final class ContentDeduplicator
{
    public const SIMILARITY_THRESHOLD = 0.85;
    public const MIN_CHARS = 100;

    private const GRAM_SIZE = 4;

    /** @var array<int, array{normalized: string, label: string}> */
    private array $kept = [];

    /**
     * Compare $body against all kept sources.
     *
     * Bodies shorter than MIN_CHARS are never evaluated (too few grams for a
     * reliable signal) and never kept.
     *
     * @return array{duplicate: bool, max_similarity: float, matched_label: ?string}
     */
    public function evaluate(string $body): array
    {
        $result = ['duplicate' => false, 'max_similarity' => 0.0, 'matched_label' => null];
        $normalized = $this->normalize($body);

        if (mb_strlen($normalized) < self::MIN_CHARS) {
            return $result;
        }

        foreach ($this->kept as $entry) {
            $sim = $this->similarity($normalized, $entry['normalized']);
            if ($sim > $result['max_similarity']) {
                $result['max_similarity'] = $sim;
                $result['matched_label'] = $entry['label'];
            }
        }

        $result['duplicate'] = $result['max_similarity'] >= self::SIMILARITY_THRESHOLD;
        return $result;
    }

    public function keep(string $body, string $label = ''): void
    {
        $normalized = $this->normalize($body);
        if (mb_strlen($normalized) < self::MIN_CHARS) {
            return;
        }
        $this->kept[] = ['normalized' => $normalized, 'label' => $label];
    }

    private function normalize(string $body): string
    {
        $normalized = mb_strtolower($body);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        return trim($normalized);
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $gramsA = $this->grams($a);
        $gramsB = $this->grams($b);
        if (empty($gramsA) || empty($gramsB)) {
            return 0.0;
        }

        $union = array_unique(array_merge($gramsA, $gramsB));
        $inter = array_intersect($gramsA, $gramsB);

        return count($inter) / count($union);
    }

    /** @return string[] unique character n-grams */
    private function grams(string $text): array
    {
        $grams = [];
        $len = mb_strlen($text);
        for ($i = 0; $i + self::GRAM_SIZE <= $len; $i++) {
            $grams[] = mb_substr($text, $i, self::GRAM_SIZE);
        }
        return array_values(array_unique($grams));
    }
}
