<?php

namespace App\Services\Tools;

use App\Search\SearchPipeline;
use App\Search\SourceSequence;

class SearchWebTool
{
    /** When true, doLiveSearch returns a placeholder instead of running the live search pipeline. */
    public static bool $testMode = false;

    private array $lastSourceMap = [];
    private array $lastBackingChunks = [];
    private array $lastSelectedChunks = [];
    /** @var array<int, array{url: string, status: string, reason: ?string}> */
    private array $lastFailures = [];

    public function __construct(
        private \App\Database $db,
        private string $uploadDir,
        private TodoistApiClient $todoist,
    ) {
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        $this->lastSourceMap = [];
        $this->lastBackingChunks = [];
        $this->lastSelectedChunks = [];
        $this->lastFailures = [];

        $rawQuery = $toolData['query'] ?? '';
        if (empty($rawQuery)) {
            return '[Web Search: No query provided.]';
        }

        $queries = self::splitQueries($rawQuery);
        $totalQueries = count($queries);

        \App\Logger::logEvent('search_start', 'Proceeding to live search', [
            'current_query' => $queries[0],
        ], 'info', 'SearchWebTool::execute');

        $results = [];
        $startSeq = SourceSequence::nextSourceSeq($this->db, $sessionId);
        $readThisTurn = [];
        foreach ($queries as $i => $q) {
            if ($totalQueries > 1) {
                \App\ProgressWriter::write($sessionId, 'search_querying', "Search {$i}/{$totalQueries}: {$q}", 'slate');
            }
            try {
                $results[] = $this->doLiveSearch($q, $messages, $emit, $sessionId, $startSeq, $readThisTurn);
            } catch (\Throwable $e) {
                \App\Logger::logEvent('search_subquery_failed', "Sub-query {$i} threw", [
                    'query' => $q,
                    'error' => $e->getMessage(),
                ], 'error', 'SearchWebTool::execute');
                \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete (partial)', 'amber');
                $results[] = "Web search for '{$q}' failed: " . $e->getMessage();
            }
            $startSeq = SourceSequence::maxSeqFromMap($this->lastSourceMap) + 1;
            // Every page this turn has read so far, so the next sub-query keeps its id instead of reading it again.
            foreach ($this->lastSourceMap as $id => $meta) {
                $key = SearchPipeline::turnUrlKey((string) ($meta['url'] ?? ''));
                if ($key !== '' && !isset($readThisTurn[$key])) {
                    $readThisTurn[$key] = (string) $id;
                }
            }
        }

        if ($totalQueries === 1) {
            return $results[0];
        }

        return self::combineResults($queries, $results, 'Search');
    }

    private function doLiveSearch(string $searchQuery, array $messages, callable $emit, int $sessionId, ?int $sourceStartSeq = null, array $alreadyRead = []): string
    {
        if (self::$testMode) {
            return '[TEST_MODE: live search disabled]';
        }
        $result = self::liveSearch($searchQuery, $messages, $emit, $sessionId, $sourceStartSeq, $alreadyRead);
        $this->lastSourceMap = array_merge($this->lastSourceMap, $result['sourceMap'] ?? []);
        $this->lastBackingChunks = array_merge($this->lastBackingChunks, $result['backingChunks'] ?? []);
        $this->lastSelectedChunks = array_merge($this->lastSelectedChunks, $result['selectedChunks'] ?? []);
        $this->lastFailures = array_merge($this->lastFailures, $result['failed'] ?? []);
        return $result['evidence'] ?? '';
    }

    public function getLastSourceMap(): array
    {
        return $this->lastSourceMap;
    }

    /** Per-URL fetch failures from the last run, for the chat source panel. */
    public function getLastFailures(): array
    {
        return $this->lastFailures;
    }

    public function getLastBackingChunks(): array
    {
        return $this->lastBackingChunks;
    }

    public function getLastSelectedChunks(): array
    {
        return $this->lastSelectedChunks;
    }

    public function resetSourceMap(): void
    {
        $this->lastSourceMap = [];
    }

    public function resetBackingChunks(): void
    {
        $this->lastBackingChunks = [];
    }

    public function resetSelectedChunks(): void
    {
        $this->lastSelectedChunks = [];
    }

    public static function liveSearch(string $searchQuery, array $messages, callable $emit, int $sessionId = 0, ?int $sourceStartSeq = null, array $alreadyRead = []): array
    {
        \App\ProgressWriter::write($sessionId, 'search_querying', "Querying search engine for: {$searchQuery}", 'slate');

        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $pipeline = new SearchPipeline(new \App\AgentManager());
            $pipeline->setSessionId($sessionId);
            if ($sourceStartSeq !== null) {
                $pipeline->setSourceStartSeq($sourceStartSeq);
            }
            $pipeline->setAlreadyRead($alreadyRead);

            try {
                $result = $pipeline->run($searchQuery, $messages, $emit);
                \App\Logger::logEvent('search_pipeline_ok', 'SearchPipeline succeeded', [
                    'query' => $searchQuery,
                    'attempt' => $attempt,
                    'evidence_len' => strlen($result['evidence'] ?? ''),
                    'source_count' => count($result['sourceIds'] ?? []),
                ], 'info', 'SearchWebTool::liveSearch');
                \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete', 'emerald');
                return $result;
            } catch (\Throwable $e) {
                $lastError = $e;
                \App\Logger::logEvent('search_pipeline_failed', 'SearchPipeline threw', [
                    'query' => $searchQuery,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 'error', 'SearchWebTool::liveSearch');
            }
        }

        return [
            'evidence' => "Web search for '{$searchQuery}' failed: " . $lastError->getMessage(),
            'sourceIds' => [],
            'sourceMap' => [],
            'backingChunks' => [],
            'selectedChunks' => [],
        ];
    }

    public static function splitQueries(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function combineResults(array $queries, array $results, string $prefix): string
    {
        $combined = '';
        foreach ($queries as $i => $q) {
            if (isset($results[$i]) && $results[$i] !== '') {
                $label = chr(65 + $i);
                $combined .= "[{$prefix} {$label}: \"{$q}\"]\n{$results[$i]}\n\n";
            }
        }
        return trim($combined);
    }
}
