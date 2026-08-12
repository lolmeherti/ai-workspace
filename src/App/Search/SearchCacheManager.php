<?php

namespace App\Search;

use App\Cache;
use App\Config;
use App\Enums\SearchIntent;
use Predis\Client;

final class SearchCacheManager
{
    /** Version constants — bump when corresponding code changes. */
    public const EXTRACTOR_VERSION = 1;
    public const CHUNKER_VERSION = 1;
    public const RETRIEVAL_VERSION = 1;
    public const CONDENSER_PROMPT_VERSION = 1;

    private Client $redis;
    private CacheStorage $storage;

    public function __construct(?CacheStorage $storage = null)
    {
        $this->redis = Cache::getClient();
        $this->storage = $storage ?? new CacheStorage();
    }

    // ── SERP Cache ─────────────────────────────────────────────────

    /**
     * Get cached SERP results for a query + engine config.
     *
     * @return Candidate[]|null
     */
    public function getSerp(string $query, string $engineConfig, SearchIntent $intent): ?array
    {
        $key = CacheKeyBuilder::serpKey($query, $engineConfig);
        $data = $this->redis->get($key);
        if (!$data) {
            return null;
        }

        $serp = json_decode($data, true);
        if (!is_array($serp) || !isset($serp['fetched_at'])) {
            return null;
        }

        $serpIntent = SearchIntent::tryFrom($serp['intent'] ?? '') ?? $intent;
        if (CacheTTL::isStale($serp['fetched_at'], $serpIntent)) {
            $this->redis->del([$key]);
            return null;
        }

        if (!isset($serp['results']) || !is_array($serp['results'])) {
            return null;
        }

        $candidates = [];
        foreach ($serp['results'] as $r) {
            $candidates[] = new Candidate(
                url: $r['url'] ?? '',
                title: $r['title'] ?? '',
                snippet: $r['snippet'] ?? '',
                domain: $r['domain'] ?? '',
                position: $r['position'] ?? 0,
                engine: $r['engine'] ?? null,
                publishedDate: $r['publishedDate'] ?? null,
            );
        }

        return $candidates;
    }

    /**
     * Cache SERP results.
     *
     * @param Candidate[] $candidates
     */
    public function setSerp(string $query, string $engineConfig, array $candidates, SearchIntent $intent): void
    {
        $key = CacheKeyBuilder::serpKey($query, $engineConfig);
        $ttl = CacheTTL::forSerp();

        $results = [];
        foreach ($candidates as $c) {
            $results[] = [
                'url'           => $c->url,
                'title'         => $c->title,
                'snippet'       => $c->snippet,
                'domain'        => $c->domain,
                'position'      => $c->position,
                'engine'        => $c->engine,
                'publishedDate' => $c->publishedDate,
            ];
        }

        $serp = [
            'results'    => $results,
            'fetched_at' => date('c'),
            'ttl'        => $ttl,
            'intent'     => $intent->value,
        ];

        $this->redis->setex($key, $ttl, json_encode($serp));
    }

    // ── Document Metadata ──────────────────────────────────────────

    /**
     * Get cached document metadata for a URL.
     *
     * Returns null if not cached or force-expired.
     */
    public function getDocMeta(string $url, SearchIntent $intent): ?array
    {
        $key = CacheKeyBuilder::docMetaKey($url);
        $meta = $this->redis->hGetAll($key);

        if (empty($meta) || !isset($meta['fetched_at'])) {
            return null;
        }

        if (CacheTTL::isForceExpired($meta['fetched_at'], $intent)) {
            $this->invalidateDoc($url);
            return null;
        }

        return $meta;
    }

    /**
     * Get the revalidation tokens (ETag, Last-Modified) for a URL
     * if the document is cached but stale.
     *
     * Returns null if no cached metadata or force-expired.
     * Returns ['etag' => ..., 'last_modified' => ...] if conditional
     * revalidation headers are available.
     */
    public function getRevalidationHeaders(string $url, SearchIntent $intent): ?array
    {
        $key = CacheKeyBuilder::docMetaKey($url);
        $meta = $this->redis->hGetAll($key);

        if (empty($meta) || !isset($meta['fetched_at'])) {
            return null;
        }

        if (CacheTTL::isForceExpired($meta['fetched_at'], $intent)) {
            $this->invalidateDoc($url);
            return null;
        }

        $headers = [];
        if (!empty($meta['etag'])) {
            $headers['If-None-Match'] = $meta['etag'];
        }
        if (!empty($meta['last_modified'])) {
            $headers['If-Modified-Since'] = $meta['last_modified'];
        }

        return empty($headers) ? null : $headers;
    }

    /**
     * Store document metadata after a successful fetch.
     */
    public function setDocMeta(
        string $url,
        string $contentHash,
        FetchResult $result,
        ?string $etag = null,
        ?string $lastModified = null,
        string $fetchMethod = 'curl'
    ): void {
        $key = CacheKeyBuilder::docMetaKey($url);

        $meta = [
            'latest_content_hash' => $contentHash,
            'fetched_at'          => date('c'),
            'status_code'         => $result->statusCode,
            'content_type'        => $result->contentType ?? 'unknown',
            'fetch_method'        => $fetchMethod,
        ];

        if ($etag !== null) {
            $meta['etag'] = $etag;
        }
        if ($lastModified !== null) {
            $meta['last_modified'] = $lastModified;
        }

        $this->redis->hMSet($key, $meta);

        $ttl = CacheTTL::forIntentMax(SearchIntent::General);
        $this->redis->expire($key, $ttl);
    }

    /**
     * Record a 304 Not Modified — bump fetched_at, keep existing content hash.
     */
    public function refreshDocMeta(string $url): void
    {
        $key = CacheKeyBuilder::docMetaKey($url);
        $this->redis->hSet($key, 'fetched_at', date('c'));
    }

    /**
     * Get the content hash stored for a URL.
     */
    public function getContentHash(string $url): ?string
    {
        $key = CacheKeyBuilder::docMetaKey($url);
        $hash = $this->redis->hGet($key, 'latest_content_hash');
        return $hash ?: null;
    }

    /**
     * Check if a URL has a cached body that is still valid.
     */
    public function hasValidBody(string $url, SearchIntent $intent): bool
    {
        $meta = $this->getDocMeta($url, $intent);
        if (!$meta || empty($meta['latest_content_hash'])) {
            return false;
        }

        return $this->storage->exists($meta['latest_content_hash']);
    }

    // ── Raw Body (filesystem) ──────────────────────────────────────

    /**
     * Get a cached raw body by content hash.
     */
    public function getRawBody(string $contentHash): ?string
    {
        return $this->storage->load($contentHash);
    }

    /**
     * Get a cached raw body for a URL (resolves URL → hash → body).
     */
    public function getRawBodyForUrl(string $url, SearchIntent $intent): ?string
    {
        $meta = $this->getDocMeta($url, $intent);
        if (!$meta || empty($meta['latest_content_hash'])) {
            return null;
        }

        return $this->storage->load($meta['latest_content_hash']);
    }

    /**
     * Store a raw body. Returns the content hash.
     */
    public function setRawBody(string $content, string $contentType): string
    {
        return $this->storage->store($content, $contentType);
    }

    // ── Extraction Cache ───────────────────────────────────────────

    /**
     * Get a cached extraction.
     */
    public function getExtraction(string $contentHash): ?ExtractedDocument
    {
        $key = CacheKeyBuilder::docExtractKey($contentHash, self::EXTRACTOR_VERSION);
        $data = $this->redis->get($key);
        if (!$data) {
            return null;
        }

        $parsed = json_decode($data, true);
        if (!is_array($parsed)) {
            return null;
        }

        return new ExtractedDocument(
            url: $parsed['url'] ?? '',
            finalUrl: $parsed['finalUrl'] ?? '',
            title: $parsed['title'] ?? '',
            domain: $parsed['domain'] ?? '',
            publishedAt: $parsed['publishedAt'] ?? null,
            updatedAt: $parsed['updatedAt'] ?? null,
            fetchedAt: $parsed['fetchedAt'] ?? '',
            markdown: $parsed['markdown'] ?? '',
            extractionMethod: $parsed['extractionMethod'] ?? 'unknown',
            contentLength: $parsed['contentLength'] ?? 0,
        );
    }

    /**
     * Cache an extraction.
     */
    public function setExtraction(string $contentHash, ExtractedDocument $doc): void
    {
        $key = CacheKeyBuilder::docExtractKey($contentHash, self::EXTRACTOR_VERSION);

        $serialized = [
            'url'              => $doc->url,
            'finalUrl'         => $doc->finalUrl,
            'title'            => $doc->title,
            'domain'           => $doc->domain,
            'publishedAt'      => $doc->publishedAt,
            'updatedAt'        => $doc->updatedAt,
            'fetchedAt'        => $doc->fetchedAt,
            'markdown'         => $doc->markdown,
            'extractionMethod' => $doc->extractionMethod,
            'contentLength'    => $doc->contentLength,
        ];

        $ttl = CacheTTL::TTL_STABLE_DOCS_MAX;
        $this->redis->setex($key, $ttl, json_encode($serialized));
    }

    /**
     * Get or compute extraction for a URL.
     * If cached, returns it. If not, calls $compute callback and caches the result.
     *
     * @param callable $compute fn(string $body): ExtractedDocument
     */
    public function getOrComputeExtraction(string $url, string $body, callable $compute): ExtractedDocument
    {
        $contentHash = CacheKeyBuilder::hashContent($body);

        $cached = $this->getExtraction($contentHash);
        if ($cached !== null) {
            return $cached;
        }

        $doc = $compute($body);
        $this->setExtraction($contentHash, $doc);
        return $doc;
    }

    // ── Chunks Cache ───────────────────────────────────────────────

    /**
     * Get cached chunks for an extraction.
     *
     * @return WebChunk[]|null
     */
    public function getChunks(string $extractionHash): ?array
    {
        $key = CacheKeyBuilder::docChunksKey($extractionHash, self::CHUNKER_VERSION);
        $data = $this->redis->get($key);
        if (!$data) {
            return null;
        }

        $serialized = json_decode($data, true);
        if (!is_array($serialized)) {
            return null;
        }

        $chunks = [];
        foreach ($serialized as $s) {
            $chunks[] = new WebChunk(
                sourceId: $s['sourceId'] ?? '',
                chunkId: $s['chunkId'] ?? '',
                url: $s['url'] ?? '',
                finalUrl: $s['finalUrl'] ?? '',
                title: $s['title'] ?? '',
                domain: $s['domain'] ?? '',
                publishedAt: $s['publishedAt'] ?? null,
                updatedAt: $s['updatedAt'] ?? null,
                fetchedAt: $s['fetchedAt'] ?? '',
                headingPath: $s['headingPath'] ?? [],
                sectionType: $s['sectionType'] ?? 'paragraph',
                text: $s['text'] ?? '',
                position: $s['position'] ?? 0,
            );
        }

        return $chunks;
    }

    /**
     * Cache chunks for an extraction.
     *
     * @param WebChunk[] $chunks
     */
    public function setChunks(string $extractionHash, array $chunks): void
    {
        $key = CacheKeyBuilder::docChunksKey($extractionHash, self::CHUNKER_VERSION);

        $serialized = [];
        foreach ($chunks as $c) {
            $serialized[] = [
                'sourceId'    => $c->sourceId,
                'chunkId'     => $c->chunkId,
                'url'         => $c->url,
                'finalUrl'    => $c->finalUrl,
                'title'       => $c->title,
                'domain'      => $c->domain,
                'publishedAt' => $c->publishedAt,
                'updatedAt'   => $c->updatedAt,
                'fetchedAt'   => $c->fetchedAt,
                'headingPath' => $c->headingPath,
                'sectionType' => $c->sectionType,
                'text'        => $c->text,
                'position'    => $c->position,
            ];
        }

        $ttl = CacheTTL::TTL_STABLE_DOCS_MAX;
        $this->redis->setex($key, $ttl, json_encode($serialized));
    }

    /**
     * Get or compute chunks for an extraction.
     *
     * @param callable $compute fn(ExtractedDocument $doc, string $sourceId): WebChunk[]
     */
    public function getOrComputeChunks(ExtractedDocument $doc, string $sourceId, callable $compute): array
    {
        $extractionHash = CacheKeyBuilder::hashContent($doc->markdown);

        $cached = $this->getChunks($extractionHash);
        if ($cached !== null) {
            return $cached;
        }

        $chunks = $compute($doc, $sourceId);
        $this->setChunks($extractionHash, $chunks);
        return $chunks;
    }

    // ── Evidence Cache ─────────────────────────────────────────────

    /**
     * Get cached evidence block.
     */
    public function getEvidence(string $evidenceKey): ?string
    {
        $data = $this->redis->get($evidenceKey);
        if (!$data) {
            return null;
        }

        $parsed = json_decode($data, true);
        if (!is_array($parsed) || !isset($parsed['evidence_text'])) {
            return null;
        }

        return $parsed['evidence_text'];
    }

    /**
     * Cache an evidence block.
     */
    public function setEvidence(
        string $evidenceKey,
        string $evidenceText,
        int $condensationLevel
    ): void {
        $serialized = json_encode([
            'evidence_text'      => $evidenceText,
            'condensation_level' => $condensationLevel,
            'created_at'         => date('c'),
        ]);

        $ttl = 604800; // 7 days — freshness is handled by the cache evaluator
        $this->redis->setex($evidenceKey, $ttl, $serialized);
    }

    /**
     * Build and get/set evidence cache key from source hashes + params.
     * Returns cached evidence text or null.
     */
    public function getEvidenceByParams(
        array $sourceContentHashes,
        string $condenserModel = 'default',
        int $tokenBudget = 4096,
        string $language = 'en'
    ): ?string {
        $key = CacheKeyBuilder::evidenceKey(
            $sourceContentHashes,
            self::RETRIEVAL_VERSION,
            self::CHUNKER_VERSION,
            self::EXTRACTOR_VERSION,
            self::CONDENSER_PROMPT_VERSION,
            $condenserModel,
            $tokenBudget,
            $language
        );

        return $this->getEvidence($key);
    }

    /**
     * Build and set evidence cache key from source hashes + params.
     */
    public function setEvidenceByParams(
        array $sourceContentHashes,
        string $evidenceText,
        int $condensationLevel,
        string $condenserModel = 'default',
        int $tokenBudget = 4096,
        string $language = 'en'
    ): void {
        $key = CacheKeyBuilder::evidenceKey(
            $sourceContentHashes,
            self::RETRIEVAL_VERSION,
            self::CHUNKER_VERSION,
            self::EXTRACTOR_VERSION,
            self::CONDENSER_PROMPT_VERSION,
            $condenserModel,
            $tokenBudget,
            $language
        );

        $this->setEvidence($key, $evidenceText, $condensationLevel);
    }

    // ── Negative Cache ─────────────────────────────────────────────

    /**
     * Check if a URL has a cached negative entry for a given fetch method.
     *
     * Returns the failure status or null if no negative entry.
     */
    public function getNegativeCache(string $url, string $fetchMethod): ?array
    {
        $key = CacheKeyBuilder::negativeCacheKey($url);
        $data = $this->redis->hGetAll($key);

        if (empty($data)) {
            return null;
        }

        $method = $data['fetch_method'] ?? '';
        if ($method !== $fetchMethod) {
            return null;
        }

        $ttl = CacheTTL::TTL_NEWS_MAX;
        $detectedAt = $data['detected_at'] ?? '';
        if (!empty($detectedAt) && (time() - strtotime($detectedAt)) > $ttl) {
            $this->redis->del([$key]);
            return null;
        }

        return [
            'status'      => $data['status'] ?? 'unknown',
            'detected_at' => $detectedAt,
            'fetch_method' => $method,
        ];
    }

    /**
     * Record a negative cache entry for a URL.
     */
    public function setNegativeCache(string $url, string $status, string $fetchMethod): void
    {
        $key = CacheKeyBuilder::negativeCacheKey($url);

        $this->redis->hMSet($key, [
            'status'      => $status,
            'detected_at' => date('c'),
            'fetch_method' => $fetchMethod,
        ]);

        $this->redis->expire($key, CacheTTL::TTL_NEWS_MAX);
    }

    /**
     * Check if a URL should be completely skipped (both curl and FlareSolverr
     * have negative entries, or the only entry covers the intended method).
     */
    public function shouldSkipFetch(string $url, string $fetchMethod): bool
    {
        $negative = $this->getNegativeCache($url, $fetchMethod);
        if ($negative === null) {
            return false;
        }

        return in_array($negative['status'], ['timeout', 'empty_body', 'unsafe_url'], true);
    }

    // ── Invalidation ───────────────────────────────────────────────

    /**
     * Invalidate all cache entries for a URL.
     * Decrements reference count on the body.
     */
    public function invalidateDoc(string $url): void
    {
        $contentHash = $this->getContentHash($url);
        if ($contentHash !== null) {
            $this->storage->decrementRef($contentHash);
        }

        $metaKey = CacheKeyBuilder::docMetaKey($url);
        $negativeKey = CacheKeyBuilder::negativeCacheKey($url);

        $this->redis->del([$metaKey, $negativeKey]);
    }

    /**
     * Full cleanup: age-based cleanup of bodies older than max TTL,
     * plus trigger LRU eviction.
     */
    public function cleanup(): int
    {
        $maxAge = max(
            CacheTTL::TTL_STABLE_DOCS_MAX,
            CacheTTL::TTL_PRODUCT_SPECS_MAX,
            CacheTTL::TTL_GENERAL_MAX
        );

        return $this->storage->ageBasedCleanup($maxAge);
    }

    /**
     * Run a complete cache flush for search cache only.
     * Does NOT touch non-search Redis keys or the main application cache.
     */
    public function flush(): void
    {
        $patterns = ['serp:*', 'doc:url:*', 'doc:body:*', 'doc:extract:*',
                      'doc:chunks:*', 'evidence:*'];

        foreach ($patterns as $pattern) {
            $keys = $this->redis->keys($pattern);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        }

        $this->redis->del([CacheKeyBuilder::LRU_ZSET]);
    }

    public function getStorage(): CacheStorage
    {
        return $this->storage;
    }
}
