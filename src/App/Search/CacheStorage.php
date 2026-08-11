<?php

namespace App\Search;

use App\Cache;
use App\Config;
use Predis\Client;

final class CacheStorage
{
    private string $storageDir;
    private int $maxBytes;

    private Client $redis;

    /** Default max cache size: 500 MB. */
    private const DEFAULT_MAX_BYTES = 524_288_000;

    public function __construct(?string $storageDir = null, ?int $maxBytes = null)
    {
        $this->storageDir = $storageDir
            ?? Config::get('SEARCH_CACHE_DIR')
            ?? (__DIR__ . '/../../search-cache/');

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $this->maxBytes = $maxBytes
            ?? (int) (Config::get('SEARCH_CACHE_MAX_MB', 500)) * 1_048_576
            ?: self::DEFAULT_MAX_BYTES;

        $this->redis = Cache::getClient();
    }

    // ── Store / Load / Delete ──────────────────────────────────────

    /**
     * Gzip and store a body to the filesystem.
     * Returns the content hash (sha256 of raw input).
     */
    public function store(string $content, string $contentType): string
    {
        $rawHash = CacheKeyBuilder::hashContent($content);
        $bodyKey = CacheKeyBuilder::docBodyKey($rawHash);
        $refKey = CacheKeyBuilder::refCountKey($rawHash);

        $filePath = $this->pathForHash($rawHash);

        if (!file_exists($filePath)) {
            $gz = gzencode($content, 6);
            if ($gz === false) {
                throw new \RuntimeException('Failed to gzip content');
            }

            file_put_contents($filePath, $gz);

            $this->redis->hMSet($bodyKey, [
                'body_gz_path'  => $filePath,
                'content_type'  => $contentType,
                'size'          => strlen($content),
                'stored_at'     => date('c'),
            ]);
        }

        $this->redis->incr($refKey);

        $this->touchLru($rawHash);

        $this->maybeEvict();

        return $rawHash;
    }

    /**
     * Load and gunzip a body from the filesystem.
     */
    public function load(string $contentHash): ?string
    {
        $bodyKey = CacheKeyBuilder::docBodyKey($contentHash);
        $meta = $this->redis->hGetAll($bodyKey);

        if (empty($meta) || empty($meta['body_gz_path'])) {
            return null;
        }

        $filePath = $meta['body_gz_path'];
        if (!file_exists($filePath)) {
            $this->redis->del([$bodyKey]);
            return null;
        }

        $gz = file_get_contents($filePath);
        if ($gz === false) {
            return null;
        }

        $content = gzdecode($gz);
        if ($content === false) {
            return null;
        }

        $this->touchLru($contentHash);

        return $content;
    }

    /**
     * Check if a body exists on the filesystem.
     */
    public function exists(string $contentHash): bool
    {
        $filePath = $this->pathForHash($contentHash);
        return file_exists($filePath);
    }

    /**
     * Get body metadata from Redis without loading the file.
     */
    public function getMeta(string $contentHash): ?array
    {
        $bodyKey = CacheKeyBuilder::docBodyKey($contentHash);
        $meta = $this->redis->hGetAll($bodyKey);
        return empty($meta) ? null : $meta;
    }

    /**
     * Get the stored content type for a body.
     */
    public function getContentType(string $contentHash): ?string
    {
        $meta = $this->getMeta($contentHash);
        return $meta['content_type'] ?? null;
    }

    /**
     * Get the uncompressed size for a body.
     */
    public function getSize(string $contentHash): int
    {
        $meta = $this->getMeta($contentHash);
        return isset($meta['size']) ? (int)$meta['size'] : 0;
    }

    // ── Reference Counting ─────────────────────────────────────────

    /**
     * Increment reference count for a content hash.
     * Called when a new URL or artifact references this body.
     */
    public function incrementRef(string $contentHash): void
    {
        $this->redis->incr(CacheKeyBuilder::refCountKey($contentHash));
    }

    /**
     * Decrement reference count and delete the body if it reaches 0.
     *
     * Uses Lua for atomic check-then-delete to avoid race conditions
     * between concurrent PHP-FPM workers.
     */
    public function decrementRef(string $contentHash): bool
    {
        $refKey = CacheKeyBuilder::refCountKey($contentHash);
        $bodyKey = CacheKeyBuilder::docBodyKey($contentHash);

        $script = <<<'LUA'
            local ref_key = KEYS[1]
            local body_key = KEYS[2]
            local file_path = ARGV[1]
            local lru_key = ARGV[2]
            local hash = ARGV[3]

            local count = redis.call('DECR', ref_key)
            if count <= 0 then
                redis.call('DEL', ref_key)
                redis.call('DEL', body_key)
                redis.call('ZREM', lru_key, hash)
                return 1
            end
            return 0
        LUA;

        $shouldDelete = (bool) $this->redis->eval(
            $script,
            2,
            $refKey,
            $bodyKey,
            $this->pathForHash($contentHash),
            CacheKeyBuilder::LRU_ZSET,
            $contentHash,
        );

        if ($shouldDelete) {
            $filePath = $this->pathForHash($contentHash);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        return $shouldDelete;
    }

    /**
     * Get current reference count for a content hash.
     */
    public function getRefCount(string $contentHash): int
    {
        return (int) ($this->redis->get(CacheKeyBuilder::refCountKey($contentHash)) ?: 0);
    }

    // ── LRU Eviction ───────────────────────────────────────────────

    /**
     * Update the LRU timestamp for a content hash.
     */
    private function touchLru(string $contentHash): void
    {
        $this->redis->zAdd(CacheKeyBuilder::LRU_ZSET, time(), $contentHash);
    }

    /**
     * Trigger LRU eviction if total size exceeds the configured maximum.
     * Evicts least-recently-used bodies whose reference count is 0.
     *
     * Only bodies with ref=0 are evictable because multi-URL sharing
     * means a body could be referenced by a still-valid doc:url entry.
     */
    private function maybeEvict(): void
    {
        $currentSize = $this->getTotalSize();
        if ($currentSize <= $this->maxBytes) {
            return;
        }

        $targetBytes = (int)($this->maxBytes * 0.8);

        $candidates = $this->redis->zRange(CacheKeyBuilder::LRU_ZSET, 0, -1);
        $evicted = 0;

        foreach ($candidates as $hash) {
            if ($currentSize <= $targetBytes) {
                break;
            }

            $refKey = CacheKeyBuilder::refCountKey($hash);
            $refCount = (int) ($this->redis->get($refKey) ?: 0);

            if ($refCount > 0) {
                continue;
            }

            $bodyKey = CacheKeyBuilder::docBodyKey($hash);
            $meta = $this->redis->hGetAll($bodyKey);
            $size = isset($meta['size']) ? (int)$meta['size'] : 0;

            $filePath = $this->pathForHash($hash);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $this->redis->del([$bodyKey, $refKey]);
            $this->redis->zRem(CacheKeyBuilder::LRU_ZSET, $hash);

            $currentSize -= $size;
            $evicted++;
        }

        if ($evicted > 0) {
            error_log("CacheStorage: LRU evicted {$evicted} bodies");
        }
    }

    /**
     * Calculate total uncompressed bytes stored on disk.
     */
    public function getTotalSize(): int
    {
        $allMeta = $this->redis->keys('doc:body:*');
        $total = 0;

        foreach ($allMeta as $key) {
            if (str_contains($key, ':refs') || str_contains($key, ':lru')) {
                continue;
            }
            $meta = $this->redis->hGetAll($key);
            $total += isset($meta['size']) ? (int)$meta['size'] : 0;
        }

        return $total;
    }

    /**
     * Delete a body and its Redis metadata immediately (bypass reference counting).
     * Use only for manual cleanup, not in normal cache eviction paths.
     */
    public function forceDelete(string $contentHash): void
    {
        $bodyKey = CacheKeyBuilder::docBodyKey($contentHash);
        $refKey = CacheKeyBuilder::refCountKey($contentHash);

        $filePath = $this->pathForHash($contentHash);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $this->redis->del([$bodyKey, $refKey]);
        $this->redis->zRem(CacheKeyBuilder::LRU_ZSET, $contentHash);
    }

    /**
     * Age-based cleanup: remove bodies older than the given TTL
     * that have zero references.
     */
    public function ageBasedCleanup(int $maxAgeSeconds): int
    {
        $removed = 0;
        $cutoff = date('c', time() - $maxAgeSeconds);

        $allMeta = $this->redis->keys('doc:body:*');
        foreach ($allMeta as $key) {
            if (str_contains($key, ':refs') || str_contains($key, ':lru')) {
                continue;
            }

            $meta = $this->redis->hGetAll($key);
            $storedAt = $meta['stored_at'] ?? '';
            if (empty($storedAt) || $storedAt >= $cutoff) {
                continue;
            }

            $hash = substr($key, strlen('doc:body:'));
            $refCount = (int) ($this->redis->get(CacheKeyBuilder::refCountKey($hash)) ?: 0);
            if ($refCount > 0) {
                continue;
            }

            $filePath = $this->pathForHash($hash);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $this->redis->del([$key, CacheKeyBuilder::refCountKey($hash)]);
            $this->redis->zRem(CacheKeyBuilder::LRU_ZSET, $hash);
            $removed++;
        }

        return $removed;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function pathForHash(string $hash): string
    {
        $prefix = substr($hash, 0, 2);
        $dir = $this->storageDir . $prefix . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . $hash . '.gz';
    }
}
