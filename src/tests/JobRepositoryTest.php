<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\JobRepository;
use App\Jobs\CvRepository;
use App\Jobs\ProfileRepository;
use App\Jobs\RegistryRepository;
use App\Jobs\BlockRepository;
use App\Jobs\JobRunRepository;

class JobRepositoryTest
{
    private \App\Database $db;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        $this->db->initTables();
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $this->runJobRepository();
            $this->runCvRepository();
            $this->runProfileRepository();
            $this->runRegistryRepository();
            $this->runBlockRepository();
            $this->runJobRunRepository();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);

        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    private function runJobRepository(): void
    {
        echo "\n=== JobRepository ===\n";

        $repo = new JobRepository($this->db);

        $postedOld = date('Y-m-d H:i:s', strtotime('-2 days'));
        $postedNew = date('Y-m-d H:i:s', strtotime('-1 day'));
        $postedMismatch = date('Y-m-d H:i:s', strtotime('-3 days'));
        $postedStale = date('Y-m-d H:i:s', strtotime('-30 days'));

        $uuid = $repo->insert($this->job([
            'url' => 'https://devjobs.at/job/1',
            'posted_at' => $postedOld,
            'title' => 'Backend Engineer',
        ]));
        $fetched = $repo->getByUuid($uuid);
        $this->test('insert + getByUuid returns row', $fetched !== null && $fetched['title'] === 'Backend Engineer');

        $found = $repo->findByUrlTimestamp('https://devjobs.at/job/1', $postedOld);
        $this->test('findByUrlTimestamp matches', $found !== null && $found['uuid'] === $uuid);

        $missing = $repo->findByUrlTimestamp('https://devjobs.at/job/1', $postedMismatch);
        $this->test('findByUrlTimestamp null on timestamp mismatch', $missing === null);

        $repo->insert($this->job([
            'url' => 'https://devjobs.at/job/2',
            'posted_at' => $postedNew,
            'title' => 'Newer Posting',
        ]));

        $list = $repo->listByState('unread');
        $this->test('listByState unread returns rows', count($list) >= 2);
        $this->test('unread ordered newest first', $list[0]['url'] === 'https://devjobs.at/job/2');

        $page = $repo->listByState('unread', 1, 1);
        $this->test('listByState paginates', count($page) === 1 && $page[0]['url'] === 'https://devjobs.at/job/2');

        $counts = $repo->countsByState();
        $this->test('countsByState counts unread', ($counts['unread'] ?? 0) >= 2);

        $staleId = $repo->insert($this->job([
            'url' => 'https://devjobs.at/job/stale',
            'posted_at' => $postedStale,
            'title' => 'Stale Job',
        ]));
        $deleted = $repo->deleteStaleUnread();
        $this->test('deleteStaleUnread removes stale only', $deleted === 1 && $repo->getByUuid($staleId) === null && $repo->getByUuid($uuid) !== null);

        $remaining = array_column($repo->listByState('unread'), 'uuid');
        $deletedCount = $repo->deleteMany($remaining);
        $this->test('deleteMany removes all listed', $deletedCount === count($remaining) && $repo->getByUuid($uuid) === null);
    }

    private function runCvRepository(): void
    {
        echo "\n=== CvRepository ===\n";

        $repo = new CvRepository($this->db);
        $before = count($repo->listSelectable());

        $id = $repo->create('Primary CV', '/uploads/cv-primary.pdf', 'hash-abc');
        $this->test('create returns uuid', $id !== '');
        $this->test('create adds to selectable', count($repo->listSelectable()) === $before + 1);

        $repo->updateMarkdown($id, '# John Doe');
        $this->test('updateMarkdown persists', $repo->getByUuid($id)['extracted_markdown'] === '# John Doe');

        $repo->setActive($id, true);
        $active = $repo->getActive();
        $this->test('setActive marks active', $active !== null && $active['uuid'] === $id);

        $second = $repo->create('Secondary CV', '/uploads/cv-secondary.pdf', 'hash-def');
        $repo->setActive($second, true);
        $this->test('only one active at a time', $repo->getActive()['uuid'] === $second);

        $repo->softDelete($id);
        $this->test('softDelete sets deleted_at', $repo->getByUuid($id)['deleted_at'] !== null);
        $this->test('softDelete hides from selectable', count($repo->listSelectable()) === $before + 1);
    }

    private function runProfileRepository(): void
    {
        echo "\n=== ProfileRepository ===\n";

        $this->db->query('DELETE FROM job_profile');

        $repo = new ProfileRepository($this->db);
        $this->test('profile starts empty', $repo->get() === null);

        $repo->save(['locations' => ['Vienna'], 'work_modes' => ['remote'], 'employment_types' => ['full-time']]);
        $saved = $repo->get();
        $this->test('profile saved', $saved !== null);
        $this->test('saved profile is complete', ProfileRepository::isComplete($saved));

        $repo->save(['locations' => ['Vienna', 'remote'], 'work_modes' => ['hybrid']]);
        $updated = $repo->get();
        $this->test('save updates in place (single row)', $updated !== null && ProfileRepository::isComplete($updated));

        $this->test('incomplete without location', !ProfileRepository::isComplete(['locations' => [], 'work_modes' => ['remote']]));
        $this->test('incomplete without work mode', !ProfileRepository::isComplete(['locations' => ['Vienna'], 'work_modes' => []]));
    }

    private function runRegistryRepository(): void
    {
        echo "\n=== RegistryRepository ===\n";

        $repo = new RegistryRepository($this->db);

        $r1 = $repo->addTemplate('https://devjobs.at/jobs?location={location}', ['location' => ['vienna']]);
        $this->test('addTemplate creates entry', $r1['created'] === true);

        $r2 = $repo->addTemplate('https://devjobs.at/jobs?location={location}', ['location' => ['vienna']]);
        $this->test('addTemplate dedupes identical entry', $r2['created'] === false && $r2['uuid'] === $r1['uuid']);

        $r3 = $repo->addTemplate('https://devjobs.at/jobs?keywords={job_title}', ['job_title' => ['php']]);
        $this->test('addTemplate creates different entry', $r3['created'] === true);

        $this->test('listAll returns entries', count($repo->listAll()) >= 2);

        $r4 = $repo->addTemplate('https://devjobs.at/jobs?location={location}', ['location' => ['vienna']]);
        $this->test('duplicate template dedupes', $r4['created'] === false);

        $updated = $repo->updateTemplate($r3['uuid'], 'https://devjobs.at/jobs?keywords={job_title}', ['job_title' => ['php', 'go']]);
        $this->test('updateTemplate updates placeholders', $updated['updated'] === true);

        $entry = $repo->getByUuid($r3['uuid']);
        $this->test('placeholders decoded on read', ($entry['placeholders']['job_title'] ?? []) === ['php', 'go']);
    }

    private function runBlockRepository(): void
    {
        echo "\n=== BlockRepository ===\n";

        $repo = new BlockRepository($this->db);

        $repo->add('company', 'Bitpanda GmbH', 7);
        $this->test('active company block present', in_array('Bitpanda GmbH', $repo->activeValues('company'), true));

        $this->db->insert('job_blocks', ['kind' => 'company', 'value' => 'Expired Co', 'expires_at' => '2000-01-01 00:00:00']);
        $this->test('expired block excluded from active', !in_array('Expired Co', $repo->activeValues('company'), true));

        $purged = $repo->purgeExpired();
        $this->test('purgeExpired removes expired', $purged >= 1);
    }

    private function runJobRunRepository(): void
    {
        echo "\n=== JobRunRepository ===\n";

        $repo = new JobRunRepository($this->db);

        $runId = $repo->create('cv-1', ['locations' => ['Vienna']]);
        $this->test('create returns uuid', $runId !== '');

        $active = $repo->getActiveRun();
        $this->test('getActiveRun detects running run', $active !== null && $active['uuid'] === $runId);

        $repo->log($runId, 'info', 'hello', ['a' => 1]);
        $this->test('log writes entry', count($repo->listLogs($runId)) === 1);

        $repo->complete($runId, ['jobs_scraped' => 5, 'jobs_selected' => 2, 'generated_queries' => ['q1', 'q2']]);
        $done = $repo->getByUuid($runId);
        $this->test('complete sets status', $done['status'] === 'completed');
        $this->test('complete stores counts', (int)$done['jobs_scraped'] === 5);
        $this->test('no active run after complete', $repo->getActiveRun() === null);

        $repo->clearAll();
        $this->test('clearAll empties runs and logs', $repo->getByUuid($runId) === null && count($repo->listLogs($runId)) === 0);
    }

    private function job(array $overrides): array
    {
        return array_merge([
            'source_domain' => 'devjobs.at',
            'url' => 'https://devjobs.at/job/x',
            'posted_at' => date('Y-m-d H:i:s'),
            'title' => 'Job',
            'company' => 'Bitpanda GmbH',
            'description' => 'A job description',
            'state' => 'unread',
        ], $overrides);
    }

    private function hasUuid(array $rows, string $uuid): bool
    {
        foreach ($rows as $row) {
            if (($row['uuid'] ?? null) === $uuid) {
                return true;
            }
        }
        return false;
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }
}
