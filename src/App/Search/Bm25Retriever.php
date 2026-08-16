<?php

namespace App\Search;

final readonly class RetrievalPolicy
{
    public function __construct(
        public int $minimumEvidenceDomains,
        public int $maximumSources,
        public int $maximumChunksPerDomain,
        public int $maximumEvidenceChunks,
    ) {}

    public static function default(): self
    {
        return new self(
            minimumEvidenceDomains: 1,
            maximumSources: 3,
            maximumChunksPerDomain: 4,
            maximumEvidenceChunks: 10,
        );
    }
}

class Bm25Retriever
{
    private const K1 = 1.2;
    private const B = 0.75;

    /**
     * BM25 score, sort, and return the full ranked list WITHOUT diversity
     * selection. Excludes chunks with score <= 0 (no query term matched).
     *
     * @param WebChunk[] $chunks
     * @return WebChunk[] ranked by BM25 score descending
     */
    public function rankRaw(array $chunks, string $userQuestion, string $searchQuery): array
    {
        return array_map(fn($s) => $s['chunk'], $this->scoreAll($chunks, $userQuestion, $searchQuery));
    }

    /**
     * @param WebChunk[] $chunks
     * @return array<int, array{chunk: WebChunk, score: float}> ranked desc
     */
    public function rankRawWithScores(array $chunks, string $userQuestion, string $searchQuery): array
    {
        return $this->scoreAll($chunks, $userQuestion, $searchQuery);
    }

    private function scoreAll(array $chunks, string $userQuestion, string $searchQuery): array
    {
        if (empty($chunks)) return [];

        $queryVector = $this->buildQueryTokenVector($userQuestion, $searchQuery);
        $entityTerms = $this->entityTerms($queryVector, $userQuestion, $searchQuery);
        $totalChunks = count($chunks);
        [$docFreqs, $avgLen] = $this->computeCorpusStats($chunks);

        $scored = [];
        foreach ($chunks as $chunk) {
            $titleTerms = $this->tokenizeForScoring($chunk->title);
            $headingText = implode(' > ', $chunk->headingPath);
            $headingTerms = $this->tokenizeForScoring($headingText);
            $bodyTerms = $this->tokenizeForScoring($chunk->text);

            $score =
                3.0 * $this->bm25Field($queryVector, $titleTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($chunk->title)) +
                2.0 * $this->bm25Field($queryVector, $headingTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($headingText)) +
                1.0 * $this->bm25Field($queryVector, $bodyTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($chunk->text)) +
                1.5 * $this->entityMatch($entityTerms, $chunk);

            if ($score <= 0.0) continue;
            $scored[] = ['chunk' => $chunk, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    /**
     * @param WebChunk[] $chunks
     * @return WebChunk[] ranked and diversity-filtered
     */
    public function rank(array $chunks, string $userQuestion, string $searchQuery, ?RetrievalPolicy $policy = null): array
    {
        $policy = $policy ?? RetrievalPolicy::default();
        $ranked = $this->rankRaw($chunks, $userQuestion, $searchQuery);
        return $this->selectDiverseChunks($ranked, $policy);
    }

    public function scoreFileChunks(array $chunks, string $query): array
    {
        if (empty($chunks)) return [];

        $queryVector = $this->buildQueryTokenVector($query, '');
        $entityTerms = $this->entityTerms($queryVector, $query);
        [$docFreqs, $avgLen] = $this->computeCorpusStats($chunks);
        $totalChunks = count($chunks);

        $scored = [];
        foreach ($chunks as $chunk) {
            $titleTerms = $this->tokenizeForScoring($chunk->title);
            $headingText = implode(' ', $chunk->entities);
            $headingTerms = $this->tokenizeForScoring($headingText);
            $bodyTerms = $this->tokenizeForScoring($chunk->text);

            $score =
                3.0 * $this->bm25Field($queryVector, $titleTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($chunk->title)) +
                2.0 * $this->bm25Field($queryVector, $headingTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($headingText)) +
                1.0 * $this->bm25Field($queryVector, $bodyTerms, $docFreqs, $totalChunks, $avgLen, mb_strlen($chunk->text)) +
                1.5 * $this->entityMatchInEntities($entityTerms, $chunk->entities);

            if ($score <= 0.0) continue;
            $scored[] = ['chunk' => $chunk, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    private function computeCorpusStats(array $chunks): array
    {
        $docFreqs = [];
        foreach ($chunks as $chunk) {
            $terms = $this->tokenizeForScoring($this->chunkCorpusText($chunk));
            $seen = [];
            foreach ($terms as $t) {
                if (!isset($seen[$t])) {
                    $docFreqs[$t] = ($docFreqs[$t] ?? 0) + 1;
                    $seen[$t] = true;
                }
            }
        }

        $avgLen = 0;
        foreach ($chunks as $chunk) {
            $avgLen += mb_strlen($chunk->text);
        }
        $avgLen = $avgLen / max(1, count($chunks));

        return [$docFreqs, $avgLen];
    }

    private function chunkCorpusText(object $chunk): string
    {
        $heading = property_exists($chunk, 'entities')
            ? implode(' ', $chunk->entities)
            : implode(' > ', $chunk->headingPath);
        return $chunk->title . ' ' . $heading . ' ' . $chunk->text;
    }

    private function buildQueryTokenVector(string $userQuestion, string $searchQuery): array
    {
        $userTokens = $this->tokenizeForScoring($userQuestion);
        $searchTokens = $this->tokenizeForScoring($searchQuery);
        $allTokens = array_unique(array_merge($userTokens, $searchTokens));

        $userSet = array_flip($userTokens);
        $searchSet = array_flip($searchTokens);

        $vector = [];
        foreach ($allTokens as $term) {
            $inUser = isset($userSet[$term]);
            $inSearch = isset($searchSet[$term]);
            $vector[$term] = ($inUser && $inSearch) ? 1.5 : 1.0;
        }

        return $vector;
    }

    private function tokenizeForScoring(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s\.\-_\/]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $raw = explode(' ', trim($text));
        $tokens = [];

        foreach ($raw as $token) {
            $token = trim($token, " \t\n\r\0\x0B.,;:!?()[]{}\"'");
            if (empty($token) || strlen($token) < 2) continue;

            if (strlen($token) > 32 && !str_contains($token, '-')) continue;

            $tokens[] = $token;

            if (preg_match('/[A-Z]/', $token)) {
                $parts = preg_split('/(?=[A-Z])/', $token);
                foreach ($parts as $p) {
                    $p = mb_strtolower(trim($p));
                    if (strlen($p) >= 2 && $p !== mb_strtolower($token)) {
                        $tokens[] = $p;
                    }
                }
            }

            if (str_contains($token, '_')) {
                foreach (explode('_', $token) as $p) {
                    $p = trim($p);
                    if (strlen($p) >= 2) {
                        $tokens[] = $p;
                    }
                }
            }

            if (str_contains($token, '-') && strlen($token) <= 32) {
                foreach (explode('-', $token) as $p) {
                    $p = trim($p);
                    if (strlen($p) >= 2 && $p !== $token) {
                        $tokens[] = $p;
                    }
                }
            }
        }

        $stopWords = ['the','a','an','is','are','was','were','be','been','being',
            'have','has','had','do','does','did','will','would','shall','should',
            'may','might','must','can','could','of','in','to','for','on','at',
            'by','with','from','as','into','through','during','before','after',
            'above','below','between','and','but','or','nor','not','so','than',
            'too','very','just','that','this','these','those','it','its',
            'about','up','out','if','then','here','there','when','where','why','how',
            'all','each','every','both','few','more','most','other','some','such',
            'no','only','own','same','i','me','my','we','our','you','your','he','him',
            'his','she','her','they','them','their'];

        $stopSet = array_flip($stopWords);
        return array_values(array_filter($tokens, fn($t) => !isset($stopSet[$t])));
    }

    private function bm25Idf(int $totalChunks, int $docFreq): float
    {
        if ($docFreq <= 0) return 0.0;
        return log(1.0 + (($totalChunks - $docFreq + 0.5) / ($docFreq + 0.5)));
    }

    private function bm25Field(array $queryVector, array $fieldTokens, array $docFreqs,
        int $totalChunks, float $avgLen, int $fieldLen): float
    {
        $score = 0.0;
        $fieldTerms = array_count_values($fieldTokens);
        $safeLen = max(1, $fieldLen);

        foreach ($queryVector as $term => $weight) {
            if (!isset($fieldTerms[$term])) continue;
            $tf = $fieldTerms[$term];
            $df = $docFreqs[$term] ?? 0;
            $idf = $this->bm25Idf($totalChunks, $df);

            $numerator = $tf * (self::K1 + 1);
            $denominator = $tf + self::K1 * (1 - self::B + self::B * ($safeLen / max(1, $avgLen)));
            $score += $idf * ($numerator / max(1, $denominator)) * $weight;
        }

        return $score;
    }

    private function entityTerms(array $queryVector, string ...$rawTexts): array
    {
        $entities = [];
        foreach ($queryVector as $term => $weight) {
            if (preg_match('/\d/', $term)) {
                $entities[] = $term;
            }
        }
        foreach ($rawTexts as $text) {
            if (preg_match_all('/\b\p{Lu}[\p{L}\d._-]{3,}\b/u', $text, $matches)) {
                foreach ($matches[0] as $word) {
                    $entities[] = mb_strtolower($word);
                }
            }
        }
        return array_values(array_unique($entities));
    }

    private function entityMatch(array $entityTerms, WebChunk $chunk): float
    {
        $score = 0.0;
        $lowerText = mb_strtolower($chunk->text);
        foreach ($entityTerms as $entity) {
            if (str_contains($lowerText, $entity)) {
                $score += 1.0;
            }
        }

        return $score;
    }

    private function entityMatchInEntities(array $entityTerms, array $entities): float
    {
        $lowerEntities = array_map('mb_strtolower', $entities);
        $score = 0.0;
        foreach ($entityTerms as $entity) {
            if (in_array($entity, $lowerEntities, true)) {
                $score += 1.0;
            }
        }

        return $score;
    }

    /**
     * @param WebChunk[] $rankedChunks
     * @return WebChunk[]
     */
    private function selectDiverseChunks(array $rankedChunks, RetrievalPolicy $policy): array
    {
        if (empty($rankedChunks)) return [];

        $selected = [];
        $domainsUsed = [];

        $selected[] = $rankedChunks[0];
        $domainsUsed[$rankedChunks[0]->domain] = 1;

        foreach (array_slice($rankedChunks, 1) as $chunk) {
            if (!isset($domainsUsed[$chunk->domain])) {
                $selected[] = $chunk;
                $domainsUsed[$chunk->domain] = 1;
                if (count($domainsUsed) >= $policy->minimumEvidenceDomains) break;
            }
        }

        foreach (array_slice($rankedChunks, 1) as $chunk) {
            if (count($selected) >= $policy->maximumEvidenceChunks) break;
            if (in_array($chunk, $selected, true)) continue;

            $domainCount = $domainsUsed[$chunk->domain] ?? 0;
            if ($domainCount < $policy->maximumChunksPerDomain) {
                $selected[] = $chunk;
                $domainsUsed[$chunk->domain] = $domainCount + 1;
            }
        }

        return $selected;
    }
}
