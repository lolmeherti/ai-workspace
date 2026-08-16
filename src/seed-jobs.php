<?php

declare(strict_types=1);

/**
 * Seeds 5 realistic mock jobs per pipeline state (30 total) so the Job Tracker
 * cards + detail view can be previewed without running a live search.
 *
 * Idempotent: wipes any previously seeded mocks (metadata.mock = true) first.
 *
 * Run inside the web container:
 *   docker exec ai_php_web php /var/www/html/seed-jobs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("seed-jobs.php is CLI-only.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Jobs\JobRepository;

Config::load(__DIR__);

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Is MySQL running? Start the stack first (docker compose up).\n");
    exit(1);
}

$db->initTables();

$db->executeStatement("DELETE FROM jobs WHERE JSON_EXTRACT(metadata, '$.mock') = true");

$repo = new JobRepository($db);

function ago(string $spec): string
{
    return date('Y-m-d H:i:s', strtotime($spec));
}

function timeline(array $steps): array
{
    $out = [];
    $from = 'unread';
    foreach ($steps as [$to, $spec]) {
        $out[] = ['from' => $from, 'to' => $to, 'at' => ago($spec)];
        $from = $to;
    }
    return $out;
}

function build(array $o): array
{
    $state = $o['state'];
    $reason = $o['history_reason'] ?? null;

    $job = [
        'source_domain' => $o['source_domain'],
        'url' => $o['url'],
        'posted_at' => ago($o['posted']),
        'title' => $o['title'],
        'company' => $o['company'],
        'description' => $o['description'],
        'location' => $o['location'] ?? null,
        'city' => $o['city'] ?? null,
        'country' => $o['country'] ?? null,
        'work_mode' => $o['work_mode'] ?? null,
        'employment_type' => $o['employment_type'] ?? null,
        'salary' => $o['salary'] ?? null,
        'applicant_count' => $o['applicant_count'] ?? null,
        'metadata' => ['mock' => true],
        'ai_selection_comment' => $o['ai_selection_comment'] ?? null,
        'state' => $state,
        'history_reason' => $reason,
        'state_timestamps' => [],
    ];

    switch ($state) {
        case 'interested':
            $job['interested_at'] = ago('-1 day');
            $job['state_timestamps'] = timeline([['interested', '-1 day']]);
            break;

        case 'applied':
            $job['interested_at'] = ago('-3 days');
            $job['applied_at'] = ago('-2 days');
            $job['state_timestamps'] = timeline([['interested', '-3 days'], ['applied', '-2 days']]);
            break;

        case 'interview':
            $job['interested_at'] = ago('-5 days');
            $job['applied_at'] = ago('-4 days');
            $job['interview_at'] = ago('-1 day');
            $job['interview_timestamps'] = [ago('+3 days 14:00'), ago('+6 days 10:00')];
            $job['state_timestamps'] = timeline([['interested', '-5 days'], ['applied', '-4 days'], ['interview', '-1 day']]);
            break;

        case 'offer':
            $job['interested_at'] = ago('-8 days');
            $job['applied_at'] = ago('-7 days');
            $job['interview_at'] = ago('-4 days');
            $job['offer_at'] = ago('-12 hours');
            $job['offer_compensation'] = $o['offer_compensation'] ?? null;
            $job['offer_deadline'] = ago('+10 days');
            $job['offer_notes'] = $o['offer_notes'] ?? null;
            $job['state_timestamps'] = timeline([['interested', '-8 days'], ['applied', '-7 days'], ['interview', '-4 days'], ['offer', '-12 hours']]);
            break;

        case 'history':
            $job['history_at'] = ago('-2 hours');
            $job['state_timestamps'] = match ($reason) {
                'rejected_by_company' => timeline([['interested', '-6 days'], ['applied', '-5 days'], ['history', '-2 hours']]),
                'offer_rejected' => timeline([['interested', '-10 days'], ['applied', '-9 days'], ['interview', '-6 days'], ['offer', '-2 days'], ['history', '-2 hours']]),
                'offer_accepted' => timeline([['interested', '-10 days'], ['applied', '-9 days'], ['interview', '-6 days'], ['offer', '-2 days'], ['history', '-2 hours']]),
                default => timeline([['history', '-2 hours']]),
            };
            break;
    }

    return $job;
}

$data = [
    'unread' => [
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/48321-senior-backend-engineer-go', 'posted' => '-6 hours', 'title' => 'Senior Backend Engineer (Go)', 'company' => 'Dynatrace', 'description' => 'Design and run high-throughput observability services in Go. You will own APIs handling billions of events daily and mentor a small backend team.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€78,000 – €98,000', 'applicant_count' => '23'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/88213-fullstack-typescript', 'posted' => '-1 day', 'title' => 'Full-Stack TypeScript Developer', 'company' => 'Bitpanda', 'description' => 'Build trading and payments features across React and Node. Work in a cross-functional squad shipping to millions of users.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€62,000 – €84,000', 'applicant_count' => '47'],
        ['source_domain' => 'lever.co', 'url' => 'https://jobs.lever.co/n26/9f2c1a-ml-infrastructure-engineer', 'posted' => '-2 days', 'title' => 'ML Infrastructure Engineer', 'company' => 'N26', 'description' => 'Own the training and serving platform for credit-risk models. Kubernetes, Python and a strong MLOps mindset required.', 'location' => 'Berlin', 'city' => 'Berlin', 'country' => 'Germany', 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€85,000 – €110,000', 'applicant_count' => '31'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/77301-devops-kubernetes', 'posted' => '-3 days', 'title' => 'DevOps Engineer (Kubernetes)', 'company' => 'Frequentis', 'description' => 'Operate mission-critical communication infrastructure. IaC with Terraform, GitOps, and an on-call rotation.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'on_site', 'employment_type' => 'full-time', 'salary' => '€70,000 – €90,000', 'applicant_count' => '12'],
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/51290-react-native-engineer', 'posted' => '-4 days', 'title' => 'React Native Engineer', 'company' => 'Runtastic', 'description' => 'Ship the fitness app experience on iOS and Android from a single TypeScript codebase.', 'location' => 'Linz', 'city' => 'Linz', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€55,000 – €72,000', 'applicant_count' => '18'],
    ],
    'interested' => [
        ['source_domain' => 'greenhouse.io', 'url' => 'https://boards.greenhouse.io/sentry/jobs/5512003-staff-software-engineer', 'posted' => '-2 days', 'title' => 'Staff Software Engineer', 'company' => 'Sentry', 'description' => 'Lead technical direction for the errors platform backend. Deep Python and distributed systems experience required.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€95,000 – €120,000', 'applicant_count' => '9', 'ai_selection_comment' => 'Strong match on distributed systems and Python background.'],
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/49410-backend-engineer-python', 'posted' => '-3 days', 'title' => 'Backend Engineer (Python)', 'company' => 'TourRadar', 'description' => 'Build search, pricing and booking APIs for a global travel marketplace.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€60,000 – €78,000', 'applicant_count' => '27'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/80120-data-engineer', 'posted' => '-4 days', 'title' => 'Data Engineer', 'company' => 'Erste Digital', 'description' => 'Design batch and streaming pipelines for banking analytics on Spark and Kafka.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'on_site', 'employment_type' => 'full-time', 'salary' => '€70,000 – €90,000', 'applicant_count' => '15'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/runtastic-platform-engineer', 'posted' => '-5 days', 'title' => 'Platform Engineer', 'company' => 'Runtastic', 'description' => 'Improve developer experience and cloud infrastructure powering the fitness platform.', 'location' => null, 'city' => null, 'country' => null, 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€80,000 – €100,000', 'applicant_count' => '6', 'ai_selection_comment' => 'Remote-friendly and aligned with platform background.'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/storyblok-senior-frontend', 'posted' => '-6 days', 'title' => 'Senior Frontend Engineer', 'company' => 'Storyblok', 'description' => 'Own the visual editor and SDK surfaces for a headless CMS used by thousands of teams.', 'location' => 'Linz', 'city' => 'Linz', 'country' => 'Austria', 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€70,000 – €90,000', 'applicant_count' => '21'],
    ],
    'applied' => [
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/48321-senior-go-engineer', 'posted' => '-5 days', 'title' => 'Senior Go Engineer', 'company' => 'Dynatrace', 'description' => 'Build the next generation of the metrics ingestion pipeline in Go.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€80,000 – €100,000', 'applicant_count' => '19'],
        ['source_domain' => 'lever.co', 'url' => 'https://jobs.lever.co/n26/3ab8f0-software-engineer-backend', 'posted' => '-6 days', 'title' => 'Software Engineer, Backend', 'company' => 'N26', 'description' => 'Join the cards team and ship features end to end on Kotlin and AWS.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€75,000 – €95,000', 'applicant_count' => '28'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/79002-cloud-engineer-aws', 'posted' => '-7 days', 'title' => 'Cloud Engineer (AWS)', 'company' => 'Smarter Ecommerce', 'description' => 'Operate and scale the cloud platform behind e-commerce analytics.', 'location' => 'Linz', 'city' => 'Linz', 'country' => 'Austria', 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€68,000 – €85,000', 'applicant_count' => '8'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/storyblok-ruby-on-rails', 'posted' => '-8 days', 'title' => 'Ruby on Rails Developer', 'company' => 'Storyblok', 'description' => 'Work on the CMS core API and integrations layer.', 'location' => 'Linz', 'city' => 'Linz', 'country' => 'Austria', 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€65,000 – €82,000', 'applicant_count' => '11'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/80188-qa-automation-engineer', 'posted' => '-9 days', 'title' => 'QA Automation Engineer', 'company' => 'Bitpanda', 'description' => 'Own end-to-end test automation for the trading platform.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€58,000 – €74,000', 'applicant_count' => '16'],
    ],
    'interview' => [
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/48321-senior-backend-engineer', 'posted' => '-6 days', 'title' => 'Senior Backend Engineer', 'company' => 'Dynatrace', 'description' => 'Building the observability data plane in Go. Interview: system design round plus live coding.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€82,000 – €102,000', 'applicant_count' => '14'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/81230-fullstack-engineer', 'posted' => '-7 days', 'title' => 'Full-Stack Engineer', 'company' => 'Bitpanda', 'description' => 'React plus Node for the investing experience. Two-stage interview scheduled.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€70,000 – €88,000', 'applicant_count' => '33'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/80120-data-engineer-sr', 'posted' => '-8 days', 'title' => 'Data Engineer', 'company' => 'Erste Digital', 'description' => 'Spark pipelines for banking data. Final round: data modelling plus SQL.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'on_site', 'employment_type' => 'full-time', 'salary' => '€72,000 – €90,000', 'applicant_count' => '9'],
        ['source_domain' => 'greenhouse.io', 'url' => 'https://boards.greenhouse.io/sentry/jobs/5533010-platform-engineer', 'posted' => '-9 days', 'title' => 'Platform Engineer', 'company' => 'Sentry', 'description' => 'Python plus Kubernetes. Hiring-manager call done, technical round next week.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€95,000 – €115,000', 'applicant_count' => '5'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/storyblok-senior-ts-engineer', 'posted' => '-10 days', 'title' => 'Senior TypeScript Engineer', 'company' => 'Storyblok', 'description' => 'Own SDK and editor UI. Remote pairing session scheduled.', 'location' => null, 'city' => null, 'country' => null, 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€78,000 – €96,000', 'applicant_count' => '13'],
    ],
    'offer' => [
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/48321-senior-backend-engineer', 'posted' => '-9 days', 'title' => 'Senior Backend Engineer', 'company' => 'Dynatrace', 'description' => 'Core Go services for observability. Offer extended after final round.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€82,000 – €102,000', 'applicant_count' => '14', 'offer_compensation' => '€92,000 + bonus', 'offer_notes' => 'Includes RSU grant and relocation support.'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/80010-staff-engineer', 'posted' => '-10 days', 'title' => 'Staff Engineer', 'company' => 'Bitpanda', 'description' => 'Trading platform staff role. Offer received.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€100,000 – €130,000', 'applicant_count' => '7', 'offer_compensation' => '€108,000', 'offer_notes' => '30 days to decide.'],
        ['source_domain' => 'lever.co', 'url' => 'https://jobs.lever.co/n26/2d90a1-cloud-engineer', 'posted' => '-11 days', 'title' => 'Cloud Engineer', 'company' => 'N26', 'description' => 'AWS infrastructure for the cards squad. Offer on the table.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€80,000 – €100,000', 'applicant_count' => '22', 'offer_compensation' => '€88,000', 'offer_notes' => 'Hybrid, 2 days in office.'],
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/49555-data-platform-engineer', 'posted' => '-12 days', 'title' => 'Data Platform Engineer', 'company' => 'TourRadar', 'description' => 'Data platform team. Offer received, decision due soon.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€72,000 – €90,000', 'applicant_count' => '10', 'offer_compensation' => '€80,000', 'offer_notes' => 'Deadline this week.'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/storyblok-senior-frontend', 'posted' => '-13 days', 'title' => 'Senior Frontend Engineer', 'company' => 'Storyblok', 'description' => 'Editor team. Remote offer extended.', 'location' => null, 'city' => null, 'country' => null, 'work_mode' => 'remote', 'employment_type' => 'full-time', 'salary' => '€70,000 – €90,000', 'applicant_count' => '17', 'offer_compensation' => '€76,000', 'offer_notes' => 'Fully remote, async-friendly.'],
    ],
    'history' => [
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/49910-fullstack-engineer', 'posted' => '-12 days', 'title' => 'Full-Stack Engineer', 'company' => 'Runtastic', 'description' => 'Progressed to final round; company moved forward with another candidate.', 'location' => 'Linz', 'city' => 'Linz', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€60,000 – €78,000', 'applicant_count' => '20', 'history_reason' => 'rejected_by_company'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/77500-qa-engineer', 'posted' => '-10 days', 'title' => 'QA Engineer', 'company' => 'Karriere.at', 'description' => 'Role focused on manual testing; not aligned with automation goals.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'on_site', 'employment_type' => 'full-time', 'salary' => '€52,000 – €66,000', 'applicant_count' => '8', 'history_reason' => 'not_interested'],
        ['source_domain' => 'karriere.at', 'url' => 'https://www.karriere.at/jobs/82001-senior-backend-engineer', 'posted' => '-14 days', 'title' => 'Senior Backend Engineer', 'company' => 'Erste Digital', 'description' => 'Accepted offer; starting next month.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'on_site', 'employment_type' => 'full-time', 'salary' => '€78,000 – €95,000', 'applicant_count' => '11', 'history_reason' => 'offer_accepted'],
        ['source_domain' => 'devjobs.at', 'url' => 'https://devjobs.at/jobs/48100-backend-engineer', 'posted' => '-15 days', 'title' => 'Backend Engineer', 'company' => 'Dynatrace', 'description' => 'Declined in favour of another opportunity.', 'location' => 'Vienna', 'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'full-time', 'salary' => '€75,000 – €95,000', 'applicant_count' => '25', 'history_reason' => 'offer_rejected'],
        ['source_domain' => 'weworkremotely.com', 'url' => 'https://weworkremotely.com/remote-jobs/freelancer-wordpress-developer', 'posted' => '-8 days', 'title' => 'WordPress Developer', 'company' => 'Freelancer', 'description' => 'Agency role, not a product company.', 'location' => null, 'city' => null, 'country' => null, 'work_mode' => 'remote', 'employment_type' => 'part-time', 'salary' => '€40,000 – €50,000', 'applicant_count' => '4', 'history_reason' => 'not_interested'],
    ],
];

$inserted = [];
foreach ($data as $state => $specs) {
    foreach ($specs as $spec) {
        $spec['state'] = $state;
        $repo->insert(build($spec));
        $inserted[$state] = ($inserted[$state] ?? 0) + 1;
    }
}

echo "Seeded mock jobs:\n";
$total = 0;
foreach (['unread', 'interested', 'applied', 'interview', 'offer', 'history'] as $state) {
    $count = $inserted[$state] ?? 0;
    echo sprintf("  %-11s %d\n", $state, $count);
    $total += $count;
}
echo "Total: {$total}\n";
