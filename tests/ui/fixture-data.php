<?php
// Synthetic, disposable data for the local UI preview. Production never loads this file.
function uiFixtureData(string $scenario = 'populated'): array
{
    $roles = ['Senior Backend Engineer', 'Platform Engineer — Developer Experience and Internal Tooling', 'Software Engineer, Data Infrastructure'];
    $companies = ['Northstar Systems', 'Harbor Technologies', 'Meridian Labs'];
    $states = array_merge(array_fill(0, 12, 'unread'), ['interested', 'interested', 'applied', 'interview', 'offer', 'history']);
    $jobs = [];
    foreach ($states as $index => $state) {
        $id = $index + 1;
        $jobs[] = [
            'uuid' => 'job-' . $id, 'title' => $roles[$index % 3], 'company' => $companies[$index % 3], 'state' => $state,
            'history_reason' => $state === 'history' ? 'not_interested' : null, 'location' => 'Vienna, Austria · Remote in Europe',
            'city' => 'Vienna', 'country' => 'Austria', 'work_mode' => 'hybrid', 'employment_type' => 'Full-time',
            'salary' => '€75,000–€95,000 / year', 'applicant_count' => 24, 'posted_at' => '2026-09-12 09:00:00',
            'source_domain' => 'careers.example.com', 'url' => 'https://careers.example.com/jobs/' . $id,
            'ai_selection_comment' => "This role matches your **backend engineering experience** and flexible-working preference.\n\n- PHP and distributed systems are central to the role.\n- The advertised range matches your salary preference.\n- Confirm the required office days before applying.",
            'description' => "## About the team\n\nBuild dependable tools for a small product team. You will work with design, infrastructure, and customer support to make complex workflows clear.\n\n## What you will do\n\n- Design and maintain PHP services and public APIs.\n- Improve database reliability, observability, and response times.\n- Review changes and help teammates deliver accessible interfaces.\n\n## What you bring\n\nExperience with SQL, automated testing, production debugging, and clear technical communication.\n\n" . str_repeat("### More about the role\n\nThis longer section checks that the details pane scrolls independently and that actions remain reachable. Discuss responsibilities and expectations with the hiring team.\n\n", $id === 2 ? 16 : 2),
            'applied_at' => in_array($state, ['applied', 'interview', 'offer'], true) ? '2026-09-13 11:30:00' : null,
            'applied_cv_uuid' => 'cv-1', 'interview_timestamps' => in_array($state, ['interview', 'offer'], true) ? ['2026-09-18 14:00:00'] : [],
            'offer_compensation' => $state === 'offer' ? '€90,000 + annual bonus' : null, 'offer_deadline' => $state === 'offer' ? '2026-09-25 17:00:00' : null,
            'offer_notes' => $state === 'offer' ? 'Review the written offer and working arrangements.' : null,
            'state_timestamps' => [['from' => 'discovered', 'to' => $state, 'at' => '2026-09-13 10:30:00']],
            'metadata' => ['fixture' => true, 'source' => 'Synthetic job for interface review'],
        ];
    }
    $facts = [
        'The user prefers concise answers with links to the original evidence.',
        'The user works as a backend engineer and prefers remote or hybrid roles in Europe.',
        'The user prefers short, direct responses.',
        'Keep original sources alongside extracted facts so claims can be checked later.',
        'When reviewing a complex interface, make the current task, progress, and next action easy to find.',
        'The user enjoys Formula 1 and follows the championship throughout the season.',
    ];
    $memories = [];
    for ($i = 1; $i <= 24; $i++) $memories[] = ['id' => $i, 'created_at' => '2026-09-10 10:00:00', 'memory_text' => $i <= 6 ? $facts[$i - 1] : "Sample saved preference $i. " . $facts[$i % 6]];
    return [
        'scenario' => $scenario, 'jobs' => $scenario === 'empty' ? [] : $jobs, 'memories' => $scenario === 'empty' ? [] : $memories,
        'cvs' => $scenario === 'empty' ? [] : [
            ['uuid' => 'cv-1', 'designation' => 'Backend engineer — September 2026', 'active_flag' => 1, 'file_hash' => 'fixture-backend-cv', 'extracted_markdown' => "Experienced PHP engineer.\nSQL, APIs, distributed systems, mentoring.\nVienna / remote in Europe."],
            ['uuid' => 'cv-2', 'designation' => 'Platform and developer experience', 'active_flag' => 0, 'file_hash' => 'fixture-platform-cv', 'extracted_markdown' => ''],
        ],
        'profile' => ['locations' => ['Vienna', 'Europe'], 'work_modes' => ['remote', 'hybrid'], 'employment_types' => ['full-time'], 'salary_min' => 75000, 'salary_currency' => 'EUR', 'free_text' => 'A product team with clear ownership and flexible hours.'],
        'entries' => $scenario === 'empty' ? [] : [['uuid' => 'source-1', 'domain' => 'careers.example.com', 'url' => 'https://careers.example.com/search?q={job_title}&location={location}', 'placeholders' => ['job_title' => ['backend engineer', 'platform engineer'], 'location' => ['Vienna', 'Remote']]]],
        'blocks' => [], 'run' => null, 'logs' => [], 'condensed' => false,
    ];
}
