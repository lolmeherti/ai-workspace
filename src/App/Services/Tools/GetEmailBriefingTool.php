<?php

namespace App\Services\Tools;

use App\Agents\SchedulingAgent;
use App\Services\EmailService;

class GetEmailBriefingTool
{
    use ToolStreamHelper;

    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
                $emit('token', ['chunk' => "\n\nSyncing inboxes..."]);

                $emailService = new \App\Services\EmailService($this->db);
                $emails = $emailService->fetchRecentEmails(true);

                $todoistResponse = $this->todoist->request('GET', '/tasks');
                $tasks = isset($todoistResponse['results']) ? $todoistResponse['results'] : (is_array($todoistResponse) ? $todoistResponse : []);

                $emit('token', ['chunk' => "\n\nExtracting scheduled appointments and resolving conflicts..."]);

                $schedulingAgent = new SchedulingAgent($this->agent);
                $suggestionsArray = $schedulingAgent->extractBriefingSuggestions($emails, $tasks);

                $suggestionsTags = "";
                if (is_array($suggestionsArray) && !empty($suggestionsArray)) {
                    foreach ($suggestionsArray as $s) {
                        if (isset($s['content']) && isset($s['due_string'])) {
                            $suggestionsTags .= "[TodoistSuggest: " . $s['content'] . " | " . $s['due_string'] . "]\n";
                        }
                    }
                }

                $currentDate = date('l, F j, Y (H:i)');
                $currentTimestamp = time();
                $upcomingTasksStr = "";
                $pastTasksTodayStr = "";

                foreach ($tasks as $t) {
                    $tDueStr = isset($t['due']['datetime']) ? $t['due']['datetime'] : (isset($t['due']['date']) ? $t['due']['date'] : '');
                    if (!empty($tDueStr)) {
                        $tTimestamp = strtotime($tDueStr);
                        if ($tTimestamp !== false && $tTimestamp > 0) {
                            if ($tTimestamp < $currentTimestamp) {
                                if (date('Y-m-d', $tTimestamp) === date('Y-m-d')) {
                                    $pastTasksTodayStr .= "- \"" . $t['content'] . "\" (Scheduled for earlier today at " . date('g:i A', $tTimestamp) . ")\n";
                                }
                            } else {
                                $upcomingTasksStr .= "- \"" . $t['content'] . "\" (Due: " . $tDueStr . ")\n";
                            }
                        } else {
                            $upcomingTasksStr .= "- \"" . $t['content'] . "\" (No due time)\n";
                        }
                    } else {
                        $upcomingTasksStr .= "- \"" . $t['content'] . "\" (No due date)\n";
                    }
                }

                $instructions = "System successfully checked your inboxes:\n";
                if (empty($emails)) {
                    $instructions = "- No emails found in the last 24 hours across connected accounts.\n";
                } else {
                    foreach ($emails as $email) {
                        if (isset($email['error'])) {
                            $instructions .= "- [Account: {$email['account_label']} ({$email['account_email']})] Connection Error: {$email['error']}\n";
                        } else {
                            $instructions .= "- [Account: {$email['account_label']} ({$email['account_email']})] From: {$email['from']} | Subject: \"{$email['subject']}\" | Date: {$email['date']}\n  Brief Snippet: {$email['snippet']}\n";
                        }
                    }
                }

                if (!empty($upcomingTasksStr)) {
                    $instructions .= "\n\n[UPCOMING SCHEDULE (FUTURE EVENTS ONLY)]:\n" . $upcomingTasksStr;
                } else {
                    $instructions .= "\n\n[UPCOMING SCHEDULE (FUTURE EVENTS ONLY)]:\n- No upcoming tasks listed on your schedule.\n";
                }

                if (!empty($pastTasksTodayStr)) {
                    $instructions .= "\n\n[PAST EVENTS FROM TODAY (ALREADY COMPLETED)]:\n" . $pastTasksTodayStr;
                    $instructions .= "CRITICAL: The past events from today listed above have ALREADY occurred relative to {$currentDate}. Do NOT list them as upcoming under 'Upcoming Schedule' or 'This Week'. Instead, address them as past events and conversationally check in on how they went (e.g., 'You had a doctor appointment earlier today at 13:30â€”hope that went well!').\n";
                }

                if (!empty($suggestionsTags)) {
                    $instructions .= "\n\n[RECOMMENDED ACTIONS]:\n";
                    $instructions .= "The following suggested calendar events were extracted and cross-referenced by your background sub-agent. You MUST append these exact tags to the very end of your final briefing response so the user can review and click them:\n";
                    $instructions .= $suggestionsTags;
                }

                $instructions .= "\n[SYSTEM NOTE]: Summarize these emails and present a beautiful daily briefing. Mention each account's status. Keep your response professional, precise, and highly readable. If any accounts had connection errors, mention them gracefully.";

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];

                $aiCommentary = '';
                $commentaryBuffer = '';

                $this->agent->chat($messages, true, function($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
                    $aiCommentary .= $chunk;
                    $commentaryBuffer .= $chunk;

                    if (mb_check_encoding($commentaryBuffer, 'UTF-8')) {
                        $emit('token', ['chunk' => $commentaryBuffer]);
                        $commentaryBuffer = '';
                    }
                });

                if (!empty($commentaryBuffer)) {
                    $emit('token', ['chunk' => mb_convert_encoding($commentaryBuffer, 'UTF-8', 'UTF-8')]);
                }

                return $cleanJson . "\n\n" . $aiCommentary;
    }
}
