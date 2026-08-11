<?php

namespace App\Search;

use App\Cache;

final class SearchArtifactManager
{
    private string $storageDir;

    private const ARTIFACT_TTL = 604800;        // 7 days
    private const REHYDRATION_THRESHOLD = 3.5;   // BM25 threshold for rehydration

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? (__DIR__ . '/../../search-artifacts/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Save a search artifact. Strips evidence from conversation — stores externally.
     *
     * @param string $searchQuery
     * @param array<array{url:string, finalUrl:string, title:string, domain:string}> $sources
     * @param array<WebChunk> $evidenceChunks
     * @param string $evidencePacket The formatted evidence block
     * @param string $answer The model's final answer
     * @param array<string> $queryTargets Entities/terms extracted from query for rehydration matching
     * @return string search_id (e.g. "srch_a1b2c3d4")
     */
    public function save(
        string $searchQuery,
        array $sources,
        array $evidenceChunks,
        string $evidencePacket,
        string $answer,
        array $queryTargets
    ): string {
        $searchId = 'srch_' . bin2hex(random_bytes(8));
        $redis = Cache::getClient();

        $artifact = [
            'search_id'      => $searchId,
            'query'          => $searchQuery,
            'query_targets'  => $queryTargets,
            'sources'        => $sources,
            'source_ids'     => array_unique(array_column($evidenceChunks, 'sourceId')),
            'chunk_count'    => count($evidenceChunks),
            'evidence_hash'  => sha1($evidencePacket),
            'created_at'     => date('c'),
            'answer'         => $answer,
        ];

        // Store metadata in Redis
        $redis->hMSet("search:{$searchId}:meta", $artifact);
        $redis->expire("search:{$searchId}:meta", self::ARTIFACT_TTL);

        // Store evidence packet on filesystem (can be large)
        $evidencePath = "{$this->storageDir}{$searchId}-evidence.txt";
        file_put_contents($evidencePath, $evidencePacket);
        $redis->set("search:{$searchId}:evidence_path", $evidencePath, self::ARTIFACT_TTL);

        // Store chunks for rehydration BM25
        if (!empty($evidenceChunks)) {
            $chunksPath = "{$this->storageDir}{$searchId}-chunks.json";
            $serializable = array_map(fn(WebChunk $c) => [
                'sourceId'    => $c->sourceId,
                'chunkId'     => $c->chunkId,
                'title'       => $c->title,
                'domain'      => $c->domain,
                'headingPath' => $c->headingPath,
                'sectionType' => $c->sectionType,
                'text'        => $c->text,
                'position'    => $c->position,
            ], $evidenceChunks);
            file_put_contents($chunksPath, json_encode($serializable, JSON_UNESCAPED_UNICODE));
            $redis->set("search:{$searchId}:chunks_path", $chunksPath, self::ARTIFACT_TTL);
        }

        return $searchId;
    }

    /**
     * Load artifact metadata from Redis.
     *
     * @return array|null
     */
    public function load(string $searchId): ?array
    {
        $redis = Cache::getClient();
        $meta = $redis->hGetAll("search:{$searchId}:meta");
        if (empty($meta)) {
            return null;
        }

        if ($this->isExpired($meta)) {
            $this->delete($searchId);
            return null;
        }

        return $meta;
    }

    /**
     * Load evidence packet from filesystem.
     */
    public function loadEvidence(string $searchId): ?string
    {
        $redis = Cache::getClient();
        $path = $redis->get("search:{$searchId}:evidence_path");
        if (!$path || !file_exists($path)) {
            return null;
        }
        return file_get_contents($path);
    }

    /**
     * Load chunks for rehydration BM25 scoring.
     *
     * @return array<array>|null
     */
    public function loadChunks(string $searchId): ?array
    {
        $redis = Cache::getClient();
        $path = $redis->get("search:{$searchId}:chunks_path");
        if (!$path || !file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $chunks = json_decode($raw, true);
        return is_array($chunks) ? $chunks : null;
    }

    /**
     * Check if a follow-up message should rehydrate a recent search artifact.
     *
     * Returns compact evidence block (max 500 tokens) if relevant, null otherwise.
     */
    public function maybeRehydrate(string $userMessage, int $limit = 3): ?string
    {
        $redis = Cache::getClient();

        // Find recent artifacts
        $keys = $redis->keys('search:srch_*:meta');
        if (empty($keys)) {
            return null;
        }

        $artifacts = [];
        foreach ($keys as $key) {
            $meta = $redis->hGetAll($key);
            if (empty($meta) || $this->isExpired($meta)) {
                continue;
            }
            $meta['_key'] = $key;
            $artifacts[] = $meta;
        }

        // Sort by recency
        usort($artifacts, fn($a, $b) =>
            strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0')
        );

        $bestMatch = null;
        $bestScore = 0.0;

        foreach (array_slice($artifacts, 0, $limit) as $artifact) {
            $queryTargets = json_decode($artifact['query_targets'] ?? '[]', true) ?: [];
            $messageTargets = $this->extractEntitiesAndNouns($userMessage);

            if (!$this->hasMeaningfulTermOverlap($messageTargets, $queryTargets)) {
                continue;
            }

            $searchId = $artifact['search_id'];
            $chunks = $this->loadChunks($searchId);
            if (empty($chunks)) {
                continue;
            }

            $ranked = $this->rankChunksBM25($userMessage, $chunks);
            $topChunk = $ranked[0] ?? null;

            if ($topChunk && ($topChunk['score'] ?? 0) >= self::REHYDRATION_THRESHOLD) {
                if ($topChunk['score'] > $bestScore) {
                    $bestScore = $topChunk['score'];
                    $bestMatch = ['artifact' => $artifact, 'chunks' => $ranked];
                }
            }
        }

        if ($bestMatch === null) {
            return null;
        }

        return $this->buildCompactEvidence($bestMatch['chunks'], 500);
    }

    public function delete(string $searchId): void
    {
        $redis = Cache::getClient();

        // Remove filesystem artifacts
        $evidencePath = $redis->get("search:{$searchId}:evidence_path");
        if ($evidencePath && file_exists($evidencePath)) {
            @unlink($evidencePath);
        }

        $chunksPath = $redis->get("search:{$searchId}:chunks_path");
        if ($chunksPath && file_exists($chunksPath)) {
            @unlink($chunksPath);
        }

        // Remove Redis keys
        $redis->del([
            "search:{$searchId}:meta",
            "search:{$searchId}:evidence_path",
            "search:{$searchId}:chunks_path",
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function isExpired(array $meta): bool
    {
        $createdAt = $meta['created_at'] ?? '';
        if (empty($createdAt)) {
            return true;
        }
        return (time() - strtotime($createdAt)) > self::ARTIFACT_TTL;
    }

    private function extractEntitiesAndNouns(string $text): array
    {
        $words = preg_split('/\s+/', strtolower($text));
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
                       'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
                       'can', 'could', 'should', 'may', 'might', 'to', 'of', 'in',
                       'for', 'on', 'with', 'at', 'by', 'from', 'about', 'this',
                       'that', 'it', 'its', 'and', 'or', 'not', 'but', 'if', 'so',
                       'what', 'which', 'who', 'how', 'when', 'where', 'why',
                       'i', 'you', 'he', 'she', 'we', 'they', 'me', 'him', 'her',
                       'us', 'them', 'my', 'your', 'his', 'our', 'their',
                       'specifications', 'details', 'info', 'latest', 'compare', 'vs'];
        $stopSet = array_flip($stopWords);

        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, ".,;:!?\"'()");
            if (strlen($word) > 1 && !isset($stopSet[$word])) {
                $terms[] = $word;
            }
        }
        return array_unique($terms);
    }

    private function hasMeaningfulTermOverlap(array $messageTerms, array $artifactTerms): bool
    {
        $overlap = array_intersect($messageTerms, $artifactTerms);
        return count($overlap) >= 2;
    }

    /**
     * Simple BM25 scoring of chunks against user message.
     * Uses Robertson non-negative IDF.
     *
     * @return array<int, array{score:float, ...}>
     */
    private function rankChunksBM25(string $userMessage, array $chunks): array
    {
        $queryTerms = $this->extractEntitiesAndNouns($userMessage);
        if (empty($queryTerms) || empty($chunks)) {
            return $chunks;
        }

        $totalDocs = count($chunks);
        $k1 = 1.2;
        $b = 0.75;

        // Compute average doc length
        $avgDocLen = 0;
        $docLengths = [];
        foreach ($chunks as $chunk) {
            $len = mb_strlen($chunk['text'] ?? '');
            $docLengths[] = $len;
            $avgDocLen += $len;
        }
        $avgDocLen = $avgDocLen / max($totalDocs, 1);

        // Compute document frequencies
        $df = [];
        foreach ($queryTerms as $term) {
            $df[$term] = 0;
            foreach ($chunks as $chunk) {
                if (stripos($chunk['text'] ?? '', $term) !== false) {
                    $df[$term]++;
                }
            }
        }

        // Score each chunk
        $scored = [];
        foreach ($chunks as $idx => $chunk) {
            $text = $chunk['text'] ?? '';
            $score = 0.0;
            $docLen = $docLengths[$idx];

            foreach ($queryTerms as $term) {
                $docFreq = $df[$term] ?? 0;
                if ($docFreq === 0) continue;

                // Robertson non-negative IDF
                $idf = log(1.0 + (($totalDocs - $docFreq + 0.5) / ($docFreq + 0.5)));

                // Term frequency in this document
                $tf = substr_count(strtolower($text), $term);

                // BM25 score
                $score += $idf * (($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($docLen / max($avgDocLen, 1)))));
            }

            $chunk['score'] = $score;
            $scored[] = $chunk;
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * Build a compact evidence block from top chunks, capped at ~maxTokens.
     */
    private function buildCompactEvidence(array $chunks, int $maxTokens): string
    {
        $lines = [];
        $tokenEstimate = 0;

        foreach ($chunks as $chunk) {
            $line = '';
            if (!empty($chunk['sourceId'])) {
                $heading = !empty($chunk['headingPath'])
                    ? ' — ' . implode(' > ', $chunk['headingPath'])
                    : '';
                $line .= "[{$chunk['sourceId']}{$heading}]\n";
            }
            $line .= ($chunk['text'] ?? '') . "\n";

            $lineTokens = (int)(mb_strlen($line) / 4);
            if ($tokenEstimate + $lineTokens > $maxTokens) {
                break;
            }

            $lines[] = $line;
            $tokenEstimate += $lineTokens;
        }

        if (empty($lines)) {
            return '';
        }

        return "RETRIEVED DATA — UNTRUSTED EXTERNAL CONTENT (from previous search):\n\n" .
               implode("\n", $lines);
    }
}
