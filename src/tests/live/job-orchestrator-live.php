<?php

declare(strict_types=1);

/*
 * Live end-to-end run of the REAL job orchestrator against the current
 * registry template sources. Adds nothing to the registry — add a source
 * manually (UI or SQL) before running. Pagination is off (page 1 only).
 *
 *   docker exec ai_php_web php /var/www/html/tests/live/job-orchestrator-live.php
 */

use App\AgentManager;
use App\Config;
use App\Database;
use App\Jobs\CvRepository;
use App\Jobs\JobOrchestrator;
use App\Jobs\JobRunService;
use App\Jobs\ProfileRepository;
use App\Jobs\RegistryRepository;
use App\Jobs\TemplateExpander;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Config::load(dirname(__DIR__, 2));

$agent = new AgentManager();

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

$registryRepo = new RegistryRepository($db);
$sources = $registryRepo->listAll();

echo "SOURCES: " . count($sources) . " total\n";
$totalUrls = 0;
foreach ($sources as $src) {
    $urls = TemplateExpander::expand($src);
    $totalUrls += count($urls);
    echo "  - {$src['url']}\n";
    foreach ($urls as $u) {
        echo "      -> {$u}\n";
    }
}
echo "EXPANDED LISTING URLS: {$totalUrls}\n\n";

if ($sources === []) {
    fwrite(STDERR, "No sources. Add one to job_registry first.\n");
    exit(1);
}

$cv = (new CvRepository($db))->getActive();
if ($cv === null || trim((string) ($cv['extracted_markdown'] ?? '')) === '') {
    fwrite(STDERR, "No active CV with extracted markdown. Set a CV active and Extract Details first.\n");
    exit(1);
}

$profile = (new ProfileRepository($db))->get();
foreach (['locations', 'work_modes', 'employment_types'] as $field) {
    if (isset($profile[$field]) && is_string($profile[$field])) {
        $decoded = json_decode($profile[$field], true);
        $profile[$field] = is_array($decoded) ? $decoded : [];
    }
}
unset($profile['id'], $profile['updated_at']);

$emit = function (string $event, array $data): void {
    echo "[" . $event . "] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
};

$service = new JobRunService($db);
$orchestrator = new JobOrchestrator($db, $agent, $service);

$started = date('Y-m-d H:i:s');
echo "RUN STARTED: {$started}\n";
$runUuid = $service->start('isolated-test-cv', $profile);
echo "RUN UUID: {$runUuid}\n\n";

try {
    $summary = $orchestrator->run($runUuid, $cv['extracted_markdown'], $profile, $emit);
} catch (\Throwable $e) {
    fwrite(STDERR, "Run failed: {$e->getMessage()}\n");
    exit(1);
}

$completed = date('Y-m-d H:i:s');
echo "\n================= SUMMARY =================\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "RUN COMPLETED: {$completed}\n";
echo "\nCleanup:\n";
echo "  DELETE FROM jobs WHERE created_at >= '{$started}';\n";
echo "  DELETE FROM job_run_logs WHERE run_uuid = '{$runUuid}';\n";
echo "  DELETE FROM job_runs WHERE uuid = '{$runUuid}';\n";
