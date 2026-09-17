<?php
// Small deterministic stand-ins for Jobs, memories, and LLM responses.
function uiFixtureMemoryHtml(array $data, int $sessionId): string
{
    $memories = $data['memories']; $memoryCount = count($memories);
    ob_start(); include dirname(__DIR__, 2) . '/src/views/tab-memories.php'; return ob_get_clean();
}
function uiFixtureApi(array &$data, string $action, array $input = []): array
{
    $ok = ['status' => 'success'];
    if (in_array($action, ['manual_consolidate', 'condense', 'run_job_search', 'extract_cv'], true) && $data['scenario'] === 'busy') return ['status' => 'error', 'code' => 'model_busy', 'message' => 'The AI is working on another task. Your draft is kept.', '_http' => 409];
    if (in_array($action, ['manual_consolidate', 'condense', 'run_job_search', 'extract_cv'], true) && $data['scenario'] === 'error') return ['status' => 'error', 'code' => 'fixture_failure', 'message' => 'Simulated service failure. Saved data is unchanged.', '_http' => 503];
    switch ($action) {
        case 'get_ai_availability': return $ok + ['state' => $data['scenario'] === 'busy' ? 'busy' : 'ready', 'message' => $data['scenario'] === 'busy' ? 'The AI is working on another task.' : 'AI ready'];
        case 'get_reasoning_effort': return $ok + ['mode' => 'effort', 'value' => 'medium', 'effort' => 'medium'];
        case 'list_jobs':
            $counts = array_fill_keys(['unread', 'interested', 'applied', 'interview', 'offer', 'history'], 0); foreach ($data['jobs'] as $job) $counts[$job['state']]++;
            $jobs = array_values(array_filter($data['jobs'], fn($job) => !isset($input['state']) || $job['state'] === $input['state'])); $page = max(1, (int)($input['page'] ?? 1)); $perPage = max(1, (int)($input['per_page'] ?? 10));
            return $ok + ['jobs' => array_slice($jobs, ($page - 1) * $perPage, $perPage), 'total' => count($jobs), 'counts' => $counts];
        case 'get_job': foreach ($data['jobs'] as $job) if ($job['uuid'] === ($input['uuid'] ?? '')) return $ok + ['job' => $job]; return ['status' => 'error', 'message' => 'This job no longer exists.', '_http' => 404];
        case 'edit_job':
            foreach ($data['jobs'] as &$job) if ($job['uuid'] === ($input['uuid'] ?? '')) { foreach (array_intersect_key($input, $job) as $key => $value) $job[$key] = $key === 'interview_timestamps' ? array_values(array_filter(explode("\n", $value))) : $value; return $ok; } return ['status' => 'error', 'message' => 'Job not found.', '_http' => 404];
        case 'transition_job': case 'restore_job':
            foreach ($data['jobs'] as &$job) if ($job['uuid'] === ($input['uuid'] ?? '')) { $next = $action === 'restore_job' ? 'unread' : ($input['to'] ?? 'unread'); $job['state_timestamps'][] = ['from' => $job['state'], 'to' => $next, 'at' => '2026-09-15 12:00:00']; $job['state'] = $next; foreach (['history_reason', 'applied_at', 'applied_cv_uuid'] as $key) $job[$key] = $input[$key] ?? null; return $ok; } return ['status' => 'error', 'message' => 'Job not found.', '_http' => 404];
        case 'batch_action':
            $ids = json_decode($input['uuids'] ?? '[]', true) ?: []; if (($input['action'] ?? '') === 'delete') $data['jobs'] = array_values(array_filter($data['jobs'], fn($job) => !in_array($job['uuid'], $ids, true))); else foreach ($ids as $id) uiFixtureApi($data, 'transition_job', ['uuid' => $id, 'to' => ($input['action'] ?? '') === 'interested' ? 'interested' : 'history', 'history_reason' => 'not_interested']); return $ok + ['succeeded' => $ids, 'failed' => []];
        case 'block_company': case 'block_domain':
            $selected = uiFixtureApi($data, 'get_job', $input); if ($selected['status'] !== 'success') return $selected; $field = $action === 'block_company' ? 'company' : 'source_domain'; $value = $selected['job'][$field]; $data['blocks'][] = ['kind' => $field === 'company' ? 'company' : 'domain', 'value' => $value]; foreach ($data['jobs'] as &$job) if ($job[$field] === $value && $job['state'] === 'unread') { $job['state'] = 'history'; $job['history_reason'] = 'blocked'; } return $ok;
        case 'get_blocks': return $ok + ['blocks' => $data['blocks']];
        case 'list_cvs': return $ok + ['cvs' => $data['cvs']];
        case 'upload_cv': $data['cvs'][] = ['uuid' => 'cv-' . uniqid(), 'designation' => $input['designation'] ?: 'Preview CV', 'active_flag' => count($data['cvs']) === 0 ? 1 : 0, 'file_hash' => 'preview-upload', 'extracted_markdown' => '']; return $ok;
        case 'extract_cv': case 'set_active_cv': foreach ($data['cvs'] as &$cv) { if ($action === 'set_active_cv') $cv['active_flag'] = (int)($cv['uuid'] === $input['cv_uuid']); elseif ($cv['uuid'] === $input['cv_uuid']) $cv['extracted_markdown'] = "Synthetic CV extraction.\nPHP, SQL, APIs, and platform engineering."; } return $ok;
        case 'delete_cv': $data['cvs'] = array_values(array_filter($data['cvs'], fn($cv) => $cv['uuid'] !== ($input['cv_uuid'] ?? ''))); return $ok;
        case 'save_profile': foreach (['locations', 'work_modes', 'employment_types'] as $key) $input[$key] = json_decode($input[$key] ?? '[]', true) ?: []; $data['profile'] = $input;
        case 'get_profile': return $ok + ['profile' => $data['profile'], 'complete' => !empty($data['profile']['locations']) && !empty($data['profile']['work_modes'])];
        case 'list_registry': return $ok + ['entries' => $data['entries']];
        case 'add_registry': case 'update_registry': $id = $input['uuid'] ?? 'source-' . uniqid(); $entry = ['uuid' => $id, 'url' => $input['url'], 'domain' => parse_url($input['url'], PHP_URL_HOST), 'placeholders' => []]; foreach (['job_title', 'location'] as $key) $entry['placeholders'][$key] = array_values(array_filter(array_map('trim', explode(',', $input[$key] ?? '')))); $data['entries'] = array_values(array_filter($data['entries'], fn($item) => $item['uuid'] !== $id)); $data['entries'][] = $entry; return $ok;
        case 'delete_registry': $data['entries'] = array_values(array_filter($data['entries'], fn($entry) => $entry['uuid'] !== $input['uuid'])); return $ok;
        case 'get_run_status': return $ok + ['run' => null]; case 'list_run_logs': return $ok + ['run' => $data['run'], 'logs' => $data['logs']];
        case 'run_job_search':
            $data['run'] = ['uuid' => 'run-fixture', 'status' => 'completed', 'started_at' => '2026-09-15 12:00:00', 'jobs_scraped' => 18, 'jobs_selected' => 12, 'sources_attempted' => 2, 'sources_failed' => 0];
            return $ok + ['_events' => [
                ['event' => 'run_start', 'data' => ['run_uuid' => 'run-fixture']],
                ['event' => 'progress', 'data' => ['jobs_scraped' => 8, 'jobs_selected' => 4, 'sources_done' => 1, 'sources_total' => 2]],
                ['event' => 'run_complete', 'data' => ['summary' => $data['run']]],
            ]];
        case 'cancel_job_search': return $ok; case 'prune_jobs': $count = count($data['jobs']); $data['jobs'] = []; $data['run'] = null; $data['logs'] = []; return $ok + ['jobs_deleted' => $count, 'runs_deleted' => 1];
        case 'add_memory': if (count($data['memories']) < 500 && trim($input['memory_text'] ?? '') !== '') { $ids = array_column($data['memories'], 'id'); $data['memories'][] = ['id' => $ids ? max($ids) + 1 : 1, 'created_at' => '2026-09-15 12:00:00', 'memory_text' => trim($input['memory_text'])]; } return $ok;
        case 'update_memory': foreach ($data['memories'] as &$memory) if ($memory['id'] === (int)($input['memory_id'] ?? 0)) $memory['memory_text'] = trim($input['memory_text']); return $ok;
        case 'delete_memory': case 'delete_multiple_memories': $ids = array_map('intval', $input['selected_memories'] ?? [$input['memory_id'] ?? 0]); $data['memories'] = array_values(array_filter($data['memories'], fn($memory) => !in_array($memory['id'], $ids, true))); return $ok;
        case 'manual_consolidate': foreach ($data['memories'] as &$memory) if ($memory['id'] === 1) $memory['memory_text'] = 'The user prefers concise, direct answers with links to the original evidence.'; unset($memory); $data['memories'] = array_values(array_filter($data['memories'], fn($memory) => $memory['id'] !== 3)); return $ok + ['html' => uiFixtureMemoryHtml($data, (int)($input['session_id'] ?? 3)), 'count' => count($data['memories']), 'message' => 'Memory list refreshed. Review the combined preference.'];
        case 'condense': if (($input['commit'] ?? '0') === '1') { foreach ($input['selected_memories'] ?? [] as $text) uiFixtureApi($data, 'add_memory', ['memory_text' => $text]); $data['condensed'] = true; return $ok + ['tokens' => 1200]; } return $ok + ['summary' => "The conversation reviewed a clearer workspace.\nKeep original evidence accessible, preserve drafts, and show progress without obscuring the current task.\n\nThis is a synthetic condensation preview.", 'memories' => array_column($data['memories'], 'memory_text')];
        case 'get_files': return $ok + ['files' => [], 'total' => 0]; case 'get_emails': return $ok + ['emails' => [], 'total' => 0];
        default: return ['status' => 'error', 'message' => 'This action is not implemented by the UI fixture: ' . $action, '_http' => 501];
    }
}
