<?php

namespace App\Jobs;

use App\AgentManager;
use App\Logger;
use App\Search\Bm25Retriever;
use App\Search\WebChunk;
use App\Jobs\Adapters\GenericListing;
use App\Jobs\Adapters\DevJobsAt;

class JobOrchestrator
{
    private const BM25_TOP_K = 30;
    private const MAX_CANDIDATES = 300;
    private const MAX_LISTING_URLS = 50;

    public function __construct(
        private $db,
        private AgentManager $agentManager,
        private JobRunService $service,
    ) {
    }

    public function run(string $runUuid, string $cvMarkdown, array $profile, callable $emit): array
    {
        $stage = ['jobs' => []];
        $counters = ['candidates' => 0, 'bm25_kept' => 0, 'jobs_scraped' => 0, 'jobs_selected' => 0, 'sources_attempted' => 0, 'sources_failed' => 0];

        $jobRepo = new JobRepository($this->db);
        $blockRepo = new BlockRepository($this->db);
        $parser = new JobParser($this->agentManager);
        $evaluator = new JobEvaluator($this->agentManager);

        $jobRepo->deleteStaleUnread();
        $blockedDomains = $blockRepo->activeValues('domain');
        $blockedCompanies = $blockRepo->activeValues('company');

        $listingUrls = $this->expandSources();

        $candidates = [];
        $isCancelled = fn() => $this->service->isCancelled($runUuid);
        $total = count($listingUrls);

        foreach ($listingUrls as $i => $listingUrl) {
            if ($isCancelled()) {
                return $this->finishCancelled($runUuid, $stage, $counters);
            }
            $emit('progress', $this->progressState($counters, $listingUrl, $i, $total));
            $this->service->log($runUuid, 'list', "Listing: {$listingUrl}");
            $counters['sources_attempted']++;

            try {
                $adapter = $this->resolveAdapterForHost($listingUrl);
                $links = $adapter->discover($listingUrl, fn($msg) => $this->service->log($runUuid, 'info', $msg), $isCancelled);
            } catch (\Throwable $e) {
                $counters['sources_failed']++;
                $this->service->log($runUuid, 'error', "Listing failed: {$listingUrl} — {$e->getMessage()}");
                Logger::logEvent('job_source_failed', "Listing failed: {$listingUrl}", [
                    'url' => $listingUrl,
                    'error' => $e->getMessage(),
                ], 'warn', 'JobOrchestrator');
                continue;
            }

            if (empty($links)) {
                $counters['sources_failed']++;
                $this->service->log($runUuid, 'warn', "No job links: {$listingUrl}");
                $emit('listing_empty', ['url' => $listingUrl]);
                continue;
            }

            foreach ($links as $link) {
                if ($isCancelled()) {
                    return $this->finishCancelled($runUuid, $stage, $counters);
                }
                $url = (string) ($link['url'] ?? '');
                if ($url !== '') {
                    $candidates[GenericListing::normalizeCandidateKey($url)] = [
                        'url' => $url,
                        'title' => (string) ($link['title'] ?? ''),
                    ];
                }
            }

            if (count($candidates) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        $counters['candidates'] = count($candidates);
        $survivors = $this->rankCandidates(array_values($candidates), $cvMarkdown, $profile, $blockedDomains, $parser, $runUuid, $emit);
        $counters['bm25_kept'] = count($survivors);

        foreach ($survivors as $s) {
            if ($this->service->isCancelled($runUuid)) {
                return $this->finishCancelled($runUuid, $stage, $counters);
            }
            $this->evaluateAndStage($s['url'], $jobRepo, $parser, $evaluator, $cvMarkdown, $profile, $blockedDomains, $blockedCompanies, $stage, $counters, $runUuid, $emit, $s['text']);
        }

        $this->commit($stage);
        $summary = $this->buildSummary($counters, [], false);
        $this->service->complete($runUuid, $summary);
        $this->service->clearCancel($runUuid);
        return $summary;
    }

    private function expandSources(): array
    {
        $registryRepo = new RegistryRepository($this->db);
        $urls = [];
        foreach ($registryRepo->listAll() as $entry) {
            foreach (TemplateExpander::expand($entry) as $url) {
                if ($url !== '') {
                    $urls[] = $url;
                }
                if (count($urls) >= self::MAX_LISTING_URLS) {
                    return $urls;
                }
            }
        }
        return $urls;
    }

    private function rankCandidates(array $candidates, string $cvMarkdown, array $profile, array $blockedDomains, JobParser $parser, string $runUuid, callable $emit): array
    {
        $unique = [];
        foreach ($candidates as $c) {
            $unique[$c['url']] = $c;
        }
        $candidates = array_values($unique);

        $searchQuery = self::buildBm25Query($profile);

        $chunks = [];
        foreach ($candidates as $i => $c) {
            if ($this->service->isCancelled($runUuid)) {
                break;
            }
            $domain = parse_url($c['url'], PHP_URL_HOST) ?: '';
            if ($domain !== '' && JobMatcher::isDomainBlocked($blockedDomains, $domain)) {
                continue;
            }
            $emit('fetching', ['done' => $i, 'total' => count($candidates), 'url' => $c['url']]);
            $text = $parser->fetchText($c['url']);
            if ($text === null || $text === '') {
                continue;
            }
            $chunks[] = new WebChunk(
                sourceId: 'job',
                chunkId: (string) $i,
                url: $c['url'],
                finalUrl: $c['url'],
                title: $c['title'] ?? '',
                domain: $domain,
                publishedAt: null,
                updatedAt: null,
                fetchedAt: date('Y-m-d H:i:s'),
                headingPath: [],
                sectionType: 'job',
                text: $text,
                position: $i,
            );
        }

        if (empty($chunks)) {
            return [];
        }

        $scored = (new Bm25Retriever())->rankRawWithScores($chunks, $cvMarkdown, $searchQuery);

        foreach ($scored as $s) {
            $emit('bm25', [
                'url' => $s['chunk']->url,
                'title' => $s['chunk']->title,
                'score' => round($s['score'], 4),
            ]);
        }

        $survivors = [];
        foreach (array_slice($scored, 0, self::BM25_TOP_K) as $s) {
            $survivors[] = [
                'url' => $s['chunk']->url,
                'title' => $s['chunk']->title,
                'text' => $s['chunk']->text,
            ];
        }
        return $survivors;
    }

    private static function jobSummary(array $job): string
    {
        $summary = ($job['title'] ?? '?') . ' @ ' . ($job['company'] ?? '?');
        $extras = [];
        foreach (['location', 'salary', 'work_mode', 'employment_type'] as $field) {
            if (!empty($job[$field])) {
                $extras[] = (string) $job[$field];
            }
        }
        $extras[] = 'posted ' . ($job['posted_at'] ?? '?');
        return $summary . ' · ' . implode(' · ', $extras);
    }

    private static function buildBm25Query(array $profile): string
    {
        $parts = array_merge(
            self::listValues($profile['locations'] ?? null),
            self::listValues($profile['work_modes'] ?? null),
            self::listValues($profile['employment_types'] ?? null),
        );
        $freeText = $profile['free_text'] ?? null;
        if (is_string($freeText) && trim($freeText) !== '') {
            $parts[] = $freeText;
        }
        return implode(' ', $parts);
    }

    private static function listValues(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [$value];
        }
        return [];
    }

    private function evaluateAndStage(
        string $url,
        JobRepository $jobRepo,
        JobParser $parser,
        JobEvaluator $evaluator,
        string $cvMarkdown,
        array $profile,
        array $blockedDomains,
        array $blockedCompanies,
        array &$stage,
        array &$counters,
        string $runUuid,
        callable $emit,
        ?string $prefetchedText = null,
    ): void {
        if ($url === '') {
            return;
        }
        $counters['jobs_scraped']++;

        $job = $parser->parse($url, $prefetchedText);
        if ($job === null) {
            $reason = $parser->lastFailureReason();
            if ($reason === 'listing page') {
                $this->service->log($runUuid, 'info', "Skipped listing: {$url}");
            } else {
                $this->service->log($runUuid, 'warn', "Parse failed: {$url}" . ($reason !== null ? " — {$reason}" : ''));
            }
            return;
        }
        $summary = self::jobSummary($job);
        if (JobMatcher::isStale($job['posted_at'])) {
            $this->service->log($runUuid, 'info', "Discarded (stale): {$summary}");
            return;
        }
        if (JobMatcher::isDomainBlocked($blockedDomains, $job['source_domain'])) {
            $this->service->log($runUuid, 'info', "Discarded (domain blocked): {$summary}");
            return;
        }
        if (JobMatcher::isCompanyBlocked($blockedCompanies, $job['company'])) {
            $this->service->log($runUuid, 'info', "Discarded (company blocked): {$summary}");
            return;
        }
        if ($jobRepo->findByUrlTimestamp($job['url'], $job['posted_at']) !== null) {
            $this->service->log($runUuid, 'info', "Duplicate: {$summary}");
            return;
        }
        $verdict = $evaluator->evaluate($job, $cvMarkdown, $profile);
        $emit('evaluated', [
            'url' => $job['url'],
            'title' => $job['title'],
            'company' => $job['company'],
            'posted_at' => $job['posted_at'],
            'decision' => $verdict['decision'],
            'comment' => $verdict['comment'],
        ]);
        Logger::logEvent('job_evaluated', "Job evaluated: {$job['title']} @ {$job['company']}", [
            'url' => $job['url'],
            'title' => $job['title'],
            'company' => $job['company'],
            'posted_at' => $job['posted_at'],
            'decision' => $verdict['decision'],
            'comment' => $verdict['comment'],
        ], 'info', 'JobOrchestrator');
        $comment = trim((string) ($verdict['comment'] ?? ''));
        if ($verdict['decision'] === 'DISCARD') {
            $this->service->log($runUuid, 'info', "Discarded: {$summary}" . ($comment !== '' ? " — {$comment}" : ''));
            return;
        }

        $job['ai_selection_comment'] = $verdict['comment'];
        $stage['jobs'][] = $job;
        $counters['jobs_selected']++;
        $this->service->log($runUuid, 'keep', "Selected: {$summary}" . ($comment !== '' ? " — {$comment}" : ''));
    }

    private function finishCancelled(string $runUuid, array $stage, array $counters): array
    {
        $this->commit($stage);
        $this->service->markCancelled($runUuid);
        $this->service->clearCancel($runUuid);
        return $this->buildSummary($counters, [], true);
    }

    private function commit(array $stage): void
    {
        $jobRepo = new JobRepository($this->db);
        foreach ($stage['jobs'] as $job) {
            $jobRepo->insert($job);
        }
    }

    private function resolveAdapterForHost(string $url): JobSourceAdapter
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_ends_with($host, 'devjobs.at')) {
            return new DevJobsAt();
        }
        return new GenericListing();
    }

    private function progressState(array $c, ?string $listingUrl, int $sourcesDone, int $sourcesTotal): array
    {
        return [
            'jobs_scraped' => $c['jobs_scraped'],
            'jobs_selected' => $c['jobs_selected'],
            'sources_done' => $sourcesDone,
            'sources_total' => $sourcesTotal,
            'sources_failed' => $c['sources_failed'],
            'listing' => $listingUrl,
        ];
    }

    private function buildSummary(array $c, array $queries, bool $cancelled): array
    {
        return [
            'cancelled' => $cancelled,
            'candidates' => $c['candidates'] ?? 0,
            'bm25_kept' => $c['bm25_kept'] ?? 0,
            'jobs_scraped' => $c['jobs_scraped'],
            'jobs_selected' => $c['jobs_selected'],
            'sources_attempted' => $c['sources_attempted'],
            'sources_failed' => $c['sources_failed'],
            'generated_queries' => $queries,
        ];
    }
}
