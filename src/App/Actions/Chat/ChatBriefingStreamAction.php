<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Agents\BriefingExtractor;
use App\Agents\BriefingTriage;
use App\Services\BriefingDataService;
use App\Services\EmailService;

class ChatBriefingStreamAction extends BaseAction
{
    private const MAX_SYNTH_BODY = 4000;

    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(int $sessionId, bool $includeUnseen): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @set_time_limit(600);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $pad = str_repeat(" ", 4096);
        echo ":{$pad}\n\n";
        @flush();

        $emit = function ($event, $data) {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        };

        $tokenCounter = new \App\Search\TokenCounter();

        // ── 1. Deterministic fetch + preprocess ─────────────────────────────
        $emit('status', ['text' => "Authenticating with your email accounts..."]);
        $emit('tool_start', ['tool' => 'email_fetch', 'label' => 'Signing in to your inboxes...']);

        $onAccount = function (array $a) use ($emit) {
            $provider = $a['provider'];
            $label = $a['label'];
            if ($a['phase'] === 'start') {
                $emit('status', ['text' => "Authenticating with {$provider} ({$label})..."]);
            } elseif ($a['phase'] === 'authed') {
                $emit('status', ['text' => "Fetching messages from {$provider} ({$label})..."]);
                if ($a['auth_ms'] > 3000) {
                    $secs = number_format($a['auth_ms'] / 1000, 1);
                    $emit('trace', ['label' => "{$provider} ({$label}) took {$secs}s to authenticate — {$provider} was slow to respond", 'color' => 'amber']);
                }
            } elseif ($a['phase'] === 'fetched') {
                $n = (int) $a['count'];
                $txt = $n === 0 ? 'no new email' : ($n === 1 ? '1 new email' : "{$n} new emails");
                $emit('trace', ['label' => "{$provider} ({$label}): {$txt}", 'color' => $n > 0 ? 'emerald' : 'slate']);
            }
        };

        $dataService = new BriefingDataService($this->db, new EmailService($this->db));
        $input = $dataService->buildInput($includeUnseen, $onAccount);
        $emails = $input['emails'];
        $errors = $input['errors'];
        $omitted = $input['omitted'];
        $budgetChars = $input['budgetChars'];

        foreach ($errors as $err) {
            $addr = $err['account_email'] ?? 'Unknown';
            $emit('trace', ['label' => "Failed to fetch from {$addr}: " . ($err['error'] ?? 'unknown'), 'color' => 'rose']);
        }
        if (empty($emails) && empty($errors)) {
            $emit('trace', ['label' => 'No email accounts configured', 'color' => 'amber']);
        }
        $label = 'Fetched ' . count($emails) . ' email' . (count($emails) !== 1 ? 's' : '') . ($omitted ? " (dropped {$omitted} oldest)" : '');
        $emit('trace', ['label' => $label, 'color' => 'emerald']);
        $emit('tool_done', ['tool' => 'email_fetch', 'label' => 'Inboxes checked.']);

        $fetchSummary = $this->buildFetchSummary($emails, $errors, $omitted);
        $emit('data_fetching', ['tool' => 'email_fetch', 'status' => 'success', 'label' => 'Email fetch complete', 'payload' => $fetchSummary]);
        \App\Logger::info('Briefing email fetch: ' . $fetchSummary);
        $this->persistSystemMessage($sessionId, $fetchSummary, 'email_fetch', $tokenCounter, $emit);

        // ── 2. Calendar (deterministic partition) ───────────────────────────
        $emit('status', ['text' => "Retrieving calendar schedules..."]);
        $emit('tool_start', ['tool' => 'get_calendar_tasks', 'label' => 'Checking calendar...']);

        $tasks = $this->fetchTasks();
        $calendar = BriefingDataService::partitionCalendar($tasks);

        $emit('trace', ['label' => 'Calendar: ' . count($calendar['upcoming']) . ' upcoming, ' . count($calendar['pastToday']) . ' earlier today', 'color' => 'slate']);
        $emit('tool_done', ['tool' => 'get_calendar_tasks', 'label' => 'Calendar loaded.']);

        $calendarSummary = $this->buildCalendarSummary($calendar);
        $this->persistSystemMessage($sessionId, $calendarSummary, 'get_calendar_tasks', $tokenCounter, $emit);

        // ── 3. Triage (conditional) ─────────────────────────────────────────
        $selectedEmails = [];
        if (!empty($emails)) {
            $bodies = array_column($emails, 'body');
            if (BriefingDataService::bodiesFitWithHeadroom($bodies, $budgetChars)) {
                $selectedEmails = $emails;
            } else {
                $emit('status', ['text' => "Scanning inbox for relevance..."]);
                $emit('tool_start', ['tool' => 'triage', 'label' => 'Scanning inbox for relevance...']);
                $triage = new BriefingTriage($this->agentManager);
                $ids = $triage->select($emails);
                $idSet = array_flip($ids);
                $selectedEmails = array_values(array_filter($emails, fn ($e) => isset($idSet[$e['id']])));
                $emit('trace', ['label' => 'Triage selected ' . count($selectedEmails) . ' of ' . count($emails), 'color' => 'slate']);
                $emit('tool_done', ['tool' => 'triage', 'label' => 'Triage complete.']);
            }
        }

        // ── 4. Extraction (structured commitments → action cards) ───────────
        $actionCards = [];
        if (!empty($selectedEmails)) {
            $emit('status', ['text' => "Extracting commitments..."]);
            $emit('tool_start', ['tool' => 'extract', 'label' => 'Extracting commitments...']);
            $extractor = new BriefingExtractor($this->agentManager);
            $actionCards = $extractor->extract($selectedEmails, $tasks, $budgetChars);
            $actionCount = count($actionCards);
            $extractTrace = $actionCount === 0
                ? 'No calendar/task suggestions found'
                : 'Extracted ' . $actionCount . ' commitment' . ($actionCount !== 1 ? 's' : '');
            $emit('trace', ['label' => $extractTrace, 'color' => 'slate']);
            $emit('tool_done', ['tool' => 'extract', 'label' => 'Extraction complete.']);
        }

        // ── 5. Card map + synthesis (streamed prose with [E#] anchors) ──────
        $emailCardMap = [];
        foreach ($selectedEmails as $e) {
            $emailCardMap[(string) $e['id']] = [
                'account_id' => $e['account_id'],
                'uid'        => $e['uid'],
                'subject'    => $e['subject'],
                'from'       => $e['from'],
                'date'       => $e['date'],
                'snippet'    => $e['snippet'],
            ];
        }

        $emit('status', ['text' => "Generating executive briefing..."]);
        $emit('briefing_cards', ['emails' => $emailCardMap, 'actions' => $actionCards]);

        $finalSystem = $this->buildSystemPrompt($selectedEmails);
        $finalInput = $this->buildSynthesisInput($selectedEmails, $calendar, $actionCards, $emails, $omitted);
        $finalMessages = [
            ['role' => 'system', 'content' => $finalSystem],
            ['role' => 'user', 'content' => $finalInput],
        ];

        \App\Logger::info('Briefing final prompt: ' . mb_strlen($finalInput) . ' chars, ' . count($selectedEmails) . ' emails, ' . count($actionCards) . ' action cards');
        $emit('generating', []);

        $finalBriefingText = '';
        $this->agentManager->chat(
            $finalMessages,
            true,
            function ($chunk, $type) use ($emit, &$finalBriefingText) {
                if ($type === 'content') {
                    $finalBriefingText .= $chunk;
                    $emit('token', ['chunk' => $chunk]);
                }
            },
            null,
            'briefing_synthesis'
        );

        $briefingTitle = 'Daily Briefing - ' . date('l d/m/Y');
        $totalSessionTokens = 0;
        if ($this->db) {
            $this->db->update('chat_sessions', ['title' => $briefingTitle], ['id' => $sessionId]);
            $this->db->insert('chat_history', [
                'session_id'     => $sessionId,
                'role'           => 'assistant',
                'message'        => $finalBriefingText,
                'token_estimate' => $tokenCounter->count($finalBriefingText),
                'briefing_cards' => (!empty($emailCardMap) || !empty($actionCards))
                    ? json_encode(['emails' => $emailCardMap, 'actions' => $actionCards], JSON_UNESCAPED_UNICODE)
                    : null,
            ]);

            // The briefing writes its rows outside ChatManager's accounting path,
            // so recompute + persist the session token total here. Keeps the
            // context counter honest for follow-up questions.
            $totalSessionTokens = 0;
            foreach ($this->db->query("SELECT token_estimate FROM chat_history WHERE session_id = :sid", [':sid' => $sessionId]) as $row) {
                $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
            }
            $this->db->update('chat_sessions', ['context_tokens' => $totalSessionTokens], ['id' => $sessionId]);
        }

        $emit('done', [
            'session_id'           => $sessionId,
            'message'              => $finalBriefingText,
            'total_session_tokens' => $totalSessionTokens,
        ]);
        $emit('title_updated', ['title' => $briefingTitle]);
    }

    private function fetchTasks(): array
    {
        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            $response = $toolService->makeTodoistRequest('GET', '/tasks');
            return isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);
        } catch (\Throwable $e) {
            \App\Logger::warning('Briefing calendar fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function persistSystemMessage(int $sessionId, string $message, string $toolName, \App\Search\TokenCounter $tokenCounter, callable $emit): void
    {
        if (!$this->db || $message === '') {
            return;
        }
        try {
            $tokens = $tokenCounter->count($message);
            $this->db->insert('chat_history', [
                'session_id'     => $sessionId,
                'role'           => 'system',
                'message'        => $message,
                'message_type'   => 'data_fetching',
                'tool_name'      => $toolName,
                'token_estimate' => $tokens,
            ]);
            $historyId = (int) $this->db->getConnection()->lastInsertId();
            // Mirror ChatManager's context_data_added so the Context Data panel
            // gains the row live instead of only after a page refresh.
            $emit('context_data_added', [
                'id'             => $historyId,
                'tool_name'      => $toolName,
                'query'          => '',
                'source_count'   => 0,
                'token_estimate' => $tokens,
                'active'         => true,
            ]);
        } catch (\Throwable $e) {
            \App\Logger::warning('Briefing persist failed: ' . $e->getMessage());
        }
    }

    private function buildFetchSummary(array $emails, array $errors, int $omitted): string
    {
        $counts = [];
        foreach ($emails as $e) {
            $addr = $e['account_email'] ?? 'Unknown';
            $counts[$addr] = ($counts[$addr] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $addr => $n) {
            $parts[] = "{$addr}: {$n}";
        }
        foreach ($errors as $err) {
            $parts[] = ($err['account_email'] ?? 'Unknown') . ': ERROR';
        }
        $stats = !empty($parts) ? implode(' | ', $parts) : 'no accounts configured';

        $subjects = [];
        foreach ($emails as $e) {
            if (count($subjects) < 5 && !empty($e['subject'])) {
                $subjects[] = '[' . ($e['account_email'] ?? '') . '] ' . $e['subject'];
            }
        }

        return 'Fetched ' . count($emails) . ' emails — ' . $stats
            . ($omitted ? " ({$omitted} older omitted)" : '')
            . (!empty($subjects) ? "\n\nSample subjects:\n" . implode("\n", $subjects) : '');
    }

    private function buildCalendarSummary(array $calendar): string
    {
        $lines = [];
        if (empty($calendar['upcoming'])) {
            $lines[] = 'No tasks scheduled for the next two weeks.';
        } else {
            foreach ($calendar['upcoming'] as $t) {
                $lines[] = '- "' . $t['content'] . '" (Due: ' . $this->dueString($t) . ')';
            }
        }
        if (!empty($calendar['pastToday'])) {
            $lines[] = '[PAST EVENTS FROM TODAY]';
            foreach ($calendar['pastToday'] as $t) {
                $lines[] = '- "' . $t['content'] . '" (earlier today)';
            }
        }
        return implode("\n", $lines);
    }

    private function buildSystemPrompt(array $selectedEmails): string
    {
        $refs = '';
        foreach ($selectedEmails as $e) {
            $refs .= "[{$e['id']}] {$e['from']} — \"{$e['subject']}\"\n";
        }

        $sys = "You are a personal executive assistant. Deliver a beautifully structured daily briefing based on the data provided. Focus on priority action items, schedule highlights, and status overview. Keep the tone elegant and action-oriented.\n\n";

        if ($refs !== '') {
            $sys .= "EMAIL REFERENCES (use these ids as markers):\n" . $refs . "\n"
                . "When you discuss an email, end that item with its marker [E<id>] (e.g. [E1]). Emit ONLY the integer marker — never card HTML and never a full email tag. If you do not discuss an email, do not emit its marker.\n\n";
        }

        $sys .= "TEMPORAL AWARENESS:\n"
            . "Today's exact date and time is " . date('l, F j, Y (H:i)') . ".\n"
            . "If an event was scheduled for today but its slot has already passed, do not list it as upcoming; address it as a past event and check in on it conversationally.\n"
            . "Only treat genuine future events as upcoming.\n";

        return $sys;
    }

    private function buildSynthesisInput(array $selectedEmails, array $calendar, array $actionCards, array $allEmails, int $omitted): string
    {
        $in = "DAILY BRIEFING DATA — cover both sections.\n\n";

        $in .= "SECTION 1 — CALENDAR (next two weeks):\n";
        if (empty($calendar['upcoming'])) {
            $in .= "No tasks scheduled for the next two weeks.\n";
        } else {
            foreach ($calendar['upcoming'] as $t) {
                $in .= '- "' . $t['content'] . '" (Due: ' . $this->dueString($t) . ")\n";
            }
        }
        if (!empty($calendar['pastToday'])) {
            $in .= "\n[PAST EVENTS FROM TODAY (ALREADY OCCURRED)]:\n";
            foreach ($calendar['pastToday'] as $t) {
                $in .= '- "' . $t['content'] . "\" (scheduled earlier today)\n";
            }
            $in .= "Do not list these as upcoming; check in on them conversationally.\n";
        }

        $in .= "\nSECTION 2 — EMAILS (reference by id):\n";
        if (empty($selectedEmails)) {
            $in .= empty($allEmails)
                ? "No emails found in the last 24 hours across connected accounts.\n"
                : "No emails were selected for full review in this briefing.\n";
        } else {
            foreach ($selectedEmails as $e) {
                $body = (string) $e['body'];
                if (mb_strlen($body) > self::MAX_SYNTH_BODY) {
                    $body = mb_substr($body, 0, self::MAX_SYNTH_BODY) . '…';
                }
                $in .= "[{$e['id']}] From: {$e['from']} | Subject: {$e['subject']} | Date: {$e['date']}\n{$body}\n\n";
            }
        }

        if (!empty($actionCards)) {
            $in .= "COMMITMENTS ALREADY EXTRACTED (mention these conversationally if relevant; do NOT emit card markup — the UI shows them separately):\n";
            foreach ($actionCards as $c) {
                $in .= '- "' . $c['content'] . '" (due: ' . $c['due_string'] . ")\n";
            }
        }

        if ($omitted > 0) {
            $in .= "\n({$omitted} older emails were omitted from this briefing window.)\n";
        }

        return $in;
    }

    private function dueString(array $task): string
    {
        if (isset($task['due']['datetime'])) {
            return (string) $task['due']['datetime'];
        }
        if (isset($task['due']['date'])) {
            return (string) $task['due']['date'];
        }
        return 'No due date';
    }
}
