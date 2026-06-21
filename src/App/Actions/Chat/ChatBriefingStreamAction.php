<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Agents\SchedulingAgent;
use App\Utils\CurrentDateUtil;

class ChatBriefingStreamAction extends BaseAction
{
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

        $emit('status', ['text' => "Connecting to inbox services..."]);
        $emit('tool_start', ['tool' => 'email_fetch', 'label' => 'Checking connected inboxes...']);

        $emailService = new \App\Services\EmailService($this->db);
        $emails = $emailService->fetchRecentEmails($includeUnseen, function ($emailAddress, $label) use ($emit) {
            $emit('status', ['text' => "Gathering emails from {$emailAddress}..."]);
        });

        $accountCounts = [];
        $accountErrors = [];
        $sampleSubjects = [];
        foreach ($emails as $email) {
            if (isset($email['error'])) {
                $addr = $email['account_email'] ?? 'Unknown';
                $accountErrors[$addr] = $email['error'];
            } else {
                $addr = $email['account_email'] ?? 'Unknown';
                $accountCounts[$addr] = ($accountCounts[$addr] ?? 0) + 1;
                if (count($sampleSubjects) < 5 && !empty($email['subject'])) {
                    $sampleSubjects[] = '[' . $addr . '] ' . $email['subject'];
                }
            }
        }
        $statsParts = [];
        foreach ($accountCounts as $label => $count) {
            $statsParts[] = "{$label}: {$count}";
        }
        foreach ($accountErrors as $label => $err) {
            $statsParts[] = "{$label}: ERROR";
        }
        $statsLine = !empty($statsParts) ? implode(' | ', $statsParts) : 'no accounts configured';
        $fetchSummary = 'Fetched ' . count($emails) . ' emails — ' . $statsLine;

        foreach ($accountCounts as $addr => $count) {
            $emit('trace', ['label' => "Fetched {$count} email" . ($count !== 1 ? 's' : '') . " from {$addr}", 'color' => 'emerald']);
        }
        foreach ($accountErrors as $addr => $err) {
            $emit('trace', ['label' => "Failed to fetch from {$addr}: {$err}", 'color' => 'rose']);
        }
        if (empty($accountCounts) && empty($accountErrors)) {
            $emit('trace', ['label' => 'No email accounts configured', 'color' => 'amber']);
        }

        $emit('tool_done', ['tool' => 'email_fetch', 'label' => 'Inboxes checked.']);
        
        $emit('data_fetching', ['tool' => 'email_fetch', 'status' => 'success', 'label' => 'Email fetch complete', 'payload' => $fetchSummary . "\n\nAccounts: " . $statsLine . "\n\nSample subjects:\n" . implode("\n", $sampleSubjects)]);
        \App\Logger::info('Briefing email fetch: ' . $fetchSummary);

        if ($this->db) {
            $this->db->insert('chat_history', [
                'session_id'    => $sessionId,
                'role'          => 'system',
                'message'       => $fetchSummary . "\n\nAccounts: " . $statsLine . "\n\nSample subjects:\n" . implode("\n", $sampleSubjects),
                'message_type'  => 'data_fetching',
                'tool_name'     => 'email_fetch',
                'token_estimate' => (int)(mb_strlen($fetchSummary) / 4)
            ]);
        }

        $emailSummaries = [];
        if (empty($emails)) {
            $emailSummaries[] = "No emails found in the last 24 hours across connected accounts.";
        } else {
            $chunks = array_chunk($emails, 5);
            $totalChunks = count($chunks);
            $emit('tool_start', ['tool' => 'email_summarize', 'label' => 'Summarizing fetched emails...']);
            foreach ($chunks as $idx => $chunk) {
                $chunkNum = $idx + 1;
                $emit('status', ['text' => "Summarizing email chunk {$chunkNum} of {$totalChunks}..."]);

                $chunkSubjects = [];
                $emailsTxt = "";
                foreach ($chunk as $email) {
                    if (isset($email['error'])) {
                        $emailsTxt .= "Connection Error: " . $email['error'] . "\n";
                    } else {
                        $chunkSubjects[] = $email['subject'] ?: '[no subject]';
                        $emailsTxt .= "Email Reference tag: [Email: {$email['account_id']}:{$email['uid']}]\nFrom: " . $email['from'] . "\nSubject: " . $email['subject'] . "\nDate: " . $email['date'] . "\nSnippet: " . $email['snippet'] . "\n\n";
                    }
                }

                $subjectList = implode(', ', $chunkSubjects);
                $emit('trace', ['label' => "Chunk {$chunkNum}/{$totalChunks}: parsing " . count($chunkSubjects) . " email" . (count($chunkSubjects) !== 1 ? 's' : '') . ($subjectList ? " ({$subjectList})" : ''), 'color' => 'slate']);

                $prompt = "Summarize the following emails briefly, listing sender, subject, and key points. You must keep the exact \"Email Reference tag\" (e.g., [Email: X:Y]) intact for each email summary:\n\n" . $emailsTxt;
                $messages = [
                    ['role' => 'system', 'content' => 'You are an AI assistant.'],
                    ['role' => 'user', 'content' => $prompt]
                ];

                $summary = "";
                $this->agentManager->chat($messages, true, function ($token) use (&$summary) {
                    $summary .= $token;
                });

                $summary = trim($summary);

                $cleanSummary = preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $summary);
                $cleanSummary = preg_replace('/<think>.*?<\/think>/s', '', $cleanSummary);
                $cleanSummary = trim($cleanSummary);

                $emit('data_fetching', ['tool' => 'email_summarize', 'status' => 'success', 'label' => "Chunk {$chunkNum}/{$totalChunks} summary", 'payload' => $cleanSummary]);
                $emit('trace', ['label' => "Chunk {$chunkNum}/{$totalChunks}: summarized (" . mb_strlen($cleanSummary) . " chars)", 'color' => 'emerald']);

                \App\Logger::info("Briefing chunk {$chunkNum}/{$totalChunks}: " . mb_strlen($cleanSummary) . ' chars (raw: ' . mb_strlen($summary) . ')');

                if ($this->db) {
                    $this->db->insert('chat_history', [
                        'session_id'    => $sessionId,
                        'role'          => 'system',
                        'message'       => $cleanSummary,
                        'message_type'  => 'data_fetching',
                        'tool_name'     => 'email_summarize',
                        'token_estimate' => (int)(mb_strlen($cleanSummary) / 4)
                    ]);
                }

                $emailSummaries[] = $cleanSummary;
            }
            $emit('tool_done', ['tool' => 'email_summarize', 'label' => 'Email summaries complete.']);
        }

        $emit('status', ['text' => "Retrieving calendar schedules..."]);
        $emit('tool_start', ['tool' => 'get_todoist_tasks', 'label' => 'Checking calendar...']);

        $todoistSummary = "";
        $tasks = [];
        $pastTasksToday = [];
        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            $response = $toolService->makeTodoistRequest('GET', '/tasks');
            $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

            $emit('trace', ['label' => 'Calendar returned ' . count($tasks) . ' task' . (count($tasks) !== 1 ? 's' : ''), 'color' => 'slate']);

            if (empty($tasks)) {
                $todoistSummary = "No active tasks found in the calendar.";
            } else {
                $twoWeeksSeconds = 14 * 24 * 60 * 60;
                $now = time();
                $todayStart = strtotime('today 00:00:00');
                $todayEnd = strtotime('today 23:59:59');
                $upcomingTasks = [];

                foreach ($tasks as $task) {
                    $dueTime = null;
                    if (isset($task['due']['datetime'])) {
                        $dueTime = strtotime($task['due']['datetime']);
                    } elseif (isset($task['due']['date'])) {
                        $dueTime = strtotime($task['due']['date']);
                    }
                    if ($dueTime !== null) {
                        if ($dueTime >= $now && ($dueTime - $now) <= $twoWeeksSeconds) {
                            $upcomingTasks[] = $task;
                        } elseif ($dueTime < $now && $dueTime >= $todayStart && $dueTime <= $todayEnd) {
                            $pastTasksToday[] = $task;
                        }
                    }
                }

                if (empty($upcomingTasks)) {
                    $todoistSummary = "No tasks are scheduled for the next two weeks.";
                } else {
                    $tasksTxt = "";
                    foreach ($upcomingTasks as $task) {
                        $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                        $tasksTxt .= "- Task: \"" . $task['content'] . "\" (Due: " . $due . ")\n";
                    }

                    $prompt = "Summarize the following calendar tasks/reminders for the next two weeks, highlighting deadlines and key priorities:\n\n" . $tasksTxt;
                    $messages = [
                        ['role' => 'system', 'content' => 'You are an AI assistant.'],
                        ['role' => 'user', 'content' => $prompt]
                    ];

                    $this->agentManager->chat($messages, true, function ($token) use (&$todoistSummary) {
                        $todoistSummary .= $token;
                    });
                }
            }
        } catch (\Throwable $e) {
            $todoistSummary = "Could not retrieve calendar tasks: " . $e->getMessage();
            $emit('trace', ['label' => 'Calendar fetch failed: ' . $e->getMessage(), 'color' => 'rose']);
        }
        $emit('tool_done', ['tool' => 'get_todoist_tasks', 'label' => 'Calendar loaded.']);

        $emit('status', ['text' => "Analyzing inbox and calendar data..."]);
        $emit('tool_start', ['tool' => 'scheduling_agent', 'label' => 'Cross-referencing emails and calendar...']);

        $suggestionsTags = "";
        try {
            $schedulingAgent = new SchedulingAgent($this->agentManager);
            $suggestionsArray = $schedulingAgent->extractBriefingSuggestions($emails, $tasks);
            if (is_array($suggestionsArray) && !empty($suggestionsArray)) {
                foreach ($suggestionsArray as $s) {
                    if (isset($s['content']) && isset($s['due_string'])) {
                        $suggestionsTags .= "[TodoistSuggest: " . $s['content'] . " | " . $s['due_string'] . "]\n";
                    }
                }
            }
            $emit('trace', ['label' => 'Cross-reference found ' . count($suggestionsArray) . ' suggested action' . (count($suggestionsArray) !== 1 ? 's' : ''), 'color' => 'slate']);
        } catch (\Throwable $e) {
            $emit('trace', ['label' => 'Cross-reference failed: ' . $e->getMessage(), 'color' => 'rose']);
        }
        $emit('tool_done', ['tool' => 'scheduling_agent', 'label' => 'Cross-reference complete.']);

        $emit('status', ['text' => "Generating executive briefing..."]);

        $finalInput = "DAILY BRIEFING DATA — you MUST cover BOTH sections below.\n\n"
                    . "SECTION 1 — CALENDAR (upcoming tasks for the next two weeks):\n\n" . $todoistSummary
                    . "\n\nSECTION 2 — EMAILS (summaries of recent inbox activity):\n\n" . implode("\n\n", $emailSummaries);

        if (!empty($pastTasksToday)) {
            $pastTasksTxt = "";
            foreach ($pastTasksToday as $task) {
                $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : '');
                $timeStr = !empty($due) ? date('g:i A', strtotime($due)) : '';
                $pastTasksTxt .= "- \"" . $task['content'] . "\" (Scheduled earlier today at " . $timeStr . ")\n";
            }
            $finalInput .= "\n\n[PAST EVENTS FROM TODAY (ALREADY COMPLETED)]:\n" . $pastTasksTxt;
            $finalInput .= "CRITICAL: The past events from today listed above have ALREADY occurred. Do NOT list them as upcoming under schedule highlights or this week. Instead, address them conversationally and ask how they went (e.g. 'You had a doctor appointment earlier today at 13:30—hope that went well!').\n";
        }

        if (!empty($suggestionsTags)) {
            $finalInput .= "\n\n[RECOMMENDED ACTION CARDS]:\n";
            $finalInput .= "The following suggested calendar events were extracted and cross-referenced by your background sub-agent. You MUST append these exact tags to the very end of your final response so the user can review and click them:\n";
            $finalInput .= $suggestionsTags;
        }

        $finalSystem = "You are a personal executive assistant. Deliver a beautifully structured daily briefing based on the summaries provided. Focus on priority action items, schedule highlights, and status overview. Keep the tone elegant and action-oriented.\n\n"
                     . "TEMPORAL AWARENESS FOR DAILY BRIEFINGS:\n"
                     . "Today's date is " . CurrentDateUtil::getCurrentDate() . ".\n"
                     . "When summarizing emails, calendar tasks, or updates during your daily briefing:\n"
                     . "1. Always compare any mentioned appointment or event times against the current clock.\n"
                     . "2. If an event or appointment was scheduled for today but its slot has already passed, do not present it as an upcoming task under 'Upcoming Schedule' or 'This Week'.\n"
                     . "3. Instead, address it as a historical event from earlier today and conversationally check in on it.\n"
                     . "4. Only suggest reminder cards or upcoming scheduling options for genuine future events.\n\n"
                     . "DAILY BRIEFING SUMMARY INSTRUCTIONS:\n"
                     . "When summarizing emails, make sure to highlight any explicit dates, times, invitations, obligations, pickups, or task-like requests mentioned by the senders so the user is fully aware of their commitments. Do not write vague or lazy summaries.\n\n"
                     . "Visual Card rule: You must preserve and append the exact, literal numeric reference tag (e.g. [Email: 3:26708]) from the source summaries when mentioning any email. Do not modify these numbers or replace them with account names.\n"
                     . "Action Card rule: Append any pre-vetted suggested tags (e.g. `[TodoistSuggest: content | due_string]`) at the very end of your response.";

        $finalMessages = [
            ['role' => 'system', 'content' => $finalSystem],
            ['role' => 'user', 'content' => $finalInput]
        ];

        \App\Logger::info('Briefing final prompt: ' . mb_strlen($finalInput) . ' chars, ' . count($emailSummaries) . ' email summaries, todoist: ' . mb_strlen($todoistSummary) . ' chars');
        $emit('generating', []);

        $finalBriefingText = $this->agentManager->chat($finalMessages, false);

        $thought = '';
        $content = $finalBriefingText;

        if (preg_match('/<\|channel>thought(.*?)(?:<channel\|>|$)/s', $finalBriefingText, $matches)) {
            $thought = trim($matches[1]);
            $content = trim(preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $finalBriefingText));
        } elseif (preg_match('/<think>(.*?)(?:<\/think>|$)/s', $finalBriefingText, $matches)) {
            $thought = trim($matches[1]);
            $content = trim(preg_replace('/<think>.*?<\/think>/s', '', $finalBriefingText));
        }

        if (!empty($thought)) {
            $emit('reasoning', ['chunk' => $thought]);
        }

        $payload = json_encode([
            'event' => 'token',
            'data' => ['chunk' => $content],
            'done' => true
        ]);
        echo "data: {$payload}\n\n";
        @ob_flush();
        @flush();

        $emit('done', ['session_id' => $sessionId]);

        $briefingTitle = 'Daily Briefing - ' . date('l d/m/Y');
        if ($this->db) {
            $this->db->update('chat_sessions', ['title' => $briefingTitle], ['id' => $sessionId]);
            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role'       => 'assistant',
                'message'    => $finalBriefingText
            ]);
        }

        $emit('title_updated', ['title' => $briefingTitle]);
    }
}