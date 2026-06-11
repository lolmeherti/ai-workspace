<?php

namespace App\Controllers;

use App\ChatManager;
use App\Enums\Action;
use App\Enums\ApiAction;
use App\Enums\Tab;
use App\Repositories\ChatSessionRepository;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\MemorySelector;
use App\Agents\SchedulingAgent;

class ChatController extends BaseController
{
    private $db;
    private $chatSessionRepository;
    private $agentManager;
    private $memoryExtractor;
    private $status;

    public function __construct($db, ChatSessionRepository $chatSessionRepository, $agentManager, $memoryExtractor, $status)
    {
        $this->db = $db;
        $this->chatSessionRepository = $chatSessionRepository;
        $this->agentManager = $agentManager;
        $this->memoryExtractor = $memoryExtractor;
        $this->status = $status;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }

    private function handlePost(): void
    {
        if ($this->isApiRequest()) {
            $this->handleApiPost();
            return;
        }

        $action = $_POST['action'] ?? null;
        if ($action === Action::DELETE_MULTIPLE_SESSIONS->value || isset($_POST[Action::DELETE_MULTIPLE_SESSIONS->value])) {
            $this->deleteMultipleSessions();
        }
    }

    private function handleGet(): void
    {
        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);
        
        if ($apiAction === ApiAction::DELETE_TODOIST_TASK) {
            $this->handleDeleteTodoistTask();
            return;
        }

        if ($apiActionVal === 'create_todoist_task') {
            $this->handleCreateTodoistTask();
            return;
        }

        $newChat = $_GET['new_chat'] ?? '';
        $deleteSessionId = (int)($_GET['delete_session'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;
        $sessionId = (int)($_GET['session_id'] ?? 0);

        if ($this->db && $deleteSessionId > 0) {
            $this->chatSessionRepository->delete($deleteSessionId);
            $this->redirect("index.php?new_chat=1");
            return;
        }

        if ($this->db && $newChat === '1') {
            $newId = $this->chatSessionRepository->create('New Conversation');
            $this->redirect($this->buildUrl($newId, $activeTab));
            return;
        }

        if ($this->db && isset($_GET['toggle_star'])) {
            $this->handleToggleStar();
            return;
        }

        if ($this->db && $sessionId === 0) {
            $sessions = $this->chatSessionRepository->getAllDesc();
            if (!empty($sessions)) {
                $mostRecentId = (int)$sessions[0]['id'];
                $this->redirect($this->buildUrl($mostRecentId, $activeTab));
                return;
            } else {
                $this->redirect("index.php?new_chat=1");
                return;
            }
        }
    }

    private function handleCreateTodoistTask(): void
    {
        $content = $_GET['content'] ?? '';
        $dueString = $_GET['due_string'] ?? '';
        $bypass = ($_GET['bypass'] ?? '0') === '1';

        if (empty($content)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing task content.'], 400);
            return;
        }

        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);

            if (!$bypass) {
                $response = $toolService->makeTodoistRequest('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $normalizedProposedDue = preg_replace('/\s+at\s+/i', ' ', $dueString);
                $proposedTimestamp = strtotime($normalizedProposedDue);

                $conflictTask = null;
                $isExactDuplicate = false;

                foreach ($tasks as $t) {
                    if (strcasecmp(trim($t['content']), trim($content)) === 0) {
                        $conflictTask = $t;
                        $isExactDuplicate = true;
                        break;
                    }

                    if ($proposedTimestamp !== false && $proposedTimestamp > 0) {
                        $tDueStr = isset($t['due']['datetime']) ? $t['due']['datetime'] : (isset($t['due']['date']) ? $t['due']['date'] : '');
                        if (!empty($tDueStr)) {
                            $tTimestamp = strtotime($tDueStr);
                            if ($tTimestamp !== false && $tTimestamp > 0) {
                                if (abs($tTimestamp - $proposedTimestamp) < 1800) {
                                    $conflictTask = $t;
                                    break;
                                }
                            }
                        }
                    }
                }

                if ($conflictTask !== null) {
                    $tDueStr = isset($conflictTask['due']['datetime']) ? $conflictTask['due']['datetime'] : (isset($conflictTask['due']['date']) ? $conflictTask['due']['date'] : '');
                    $dueFormatted = '';
                    if (!empty($tDueStr)) {
                        $dueFormatted = date('g:i A', strtotime($tDueStr));
                    }

                    if ($isExactDuplicate) {
                        $msg = "Duplicate entry detected: \"" . $conflictTask['content'] . "\" is already on your list.";
                        if (!empty($dueFormatted)) {
                            $msg .= " (Scheduled for " . $dueFormatted . ")";
                        }
                    } else {
                        $msg = "Time overlap detected: \"" . $conflictTask['content'] . "\" is already scheduled for " . $dueFormatted . ".";
                    }

                    $this->jsonResponse([
                        'status' => 'conflict',
                        'message' => $msg . " Do you want to schedule this anyway?"
                    ]);
                    return;
                }
            }

            $postData = ['content' => $content];
            if (!empty($dueString)) {
                $postData['due_string'] = $dueString;
            }

            $task = $toolService->makeTodoistRequest('POST', '/tasks', $postData);

            $this->jsonResponse([
                'status' => 'success',
                'task' => $task
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function handleDeleteTodoistTask(): void
    {
        $taskId = $_GET['task_id'] ?? '';
        if (empty($taskId)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing task ID.'], 400);
            return;
        }

        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            
            $toolService->makeTodoistRequest('DELETE', '/tasks/' . $taskId);
            
            $this->jsonResponse([
                'status' => 'success', 
                'message' => 'Task successfully deleted from Todoist.'
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'status' => 'error', 
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function handleApiPost(): void
    {
        if (!$this->status->all_operational || !$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'System offline.'], 503);
            return;
        }

        $actionVal = $_POST['action'] ?? '';

        if ($actionVal === 'ai_reply_assist') {
            $this->handleReplyAssist();
            return;
        }

        $action = Action::tryFrom($actionVal);

        if ($action === Action::CONDENSE) {
            $this->handleCondenseAction();
            return;
        }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $query = $_POST['q'] ?? '';
        $imageFile = $_FILES['file'] ?? $_FILES['image'] ?? null;
        $cacheAction = $_POST['cache_action'] ?? null;
        $cacheKey = $_POST['cache_key'] ?? null;

        if (empty($query) && empty($imageFile) && empty($cacheAction)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Empty prompt.'], 400);
            return;
        }

        $cleanQuery = trim($query);
        if (str_starts_with($cleanQuery, "[TRIGGER_BRIEFING_PIPELINE]")) {
            $includeSeen = str_contains($cleanQuery, ":include_read");
            $this->streamUnifiedBriefing($sessionId, $includeSeen);
            return;
        }

        $this->streamChatResponse($sessionId, $query, $imageFile, $cacheAction, $cacheKey);
    }

    private function handleReplyAssist(): void
    {
        $originalSubject = $_POST['original_subject'] ?? '';
        $originalFrom = $_POST['original_from'] ?? '';
        $originalBody = $_POST['original_body'] ?? '';
        $userDraft = $_POST['user_draft'] ?? '';

        $systemPrompt = "You are a highly professional, friendly, and intelligent AI personal executive assistant. Your task is to write an email reply for the user based on the original email details provided.\n\n"
                      . "CRITICAL INSTRUCTIONS:\n"
                      . "1. Analyze the original email details (sender, subject, and body).\n"
                      . "2. If the user draft/instructions are EMPTY:\n"
                      . "   - Suggest a highly professional, ready-to-send reply. Maintain the language of the original email (e.g. if the original email is in German, reply in German; if English, reply in English).\n"
                      . "3. If the user draft/instructions are NOT EMPTY:\n"
                      . "   - If the user wrote notes or instructions, act strictly on them and generate the final polished reply\n"
                      . "   - If the user wrote a raw draft, refine and polish it.\n"
                      . "4. Output ONLY the clean, ready-to-send reply body text. Do NOT include any 'Subject:', 'To:', markdown code blocks (such as ```), or conversational preamble (like 'Sure, here is...'). Just output the exact reply text.";

        $userPrompt = "Original Email Sender: " . $originalFrom . "\n"
                    . "Original Email Subject: " . $originalSubject . "\n"
                    . "Original Email Body:\n" . $originalBody . "\n\n"
                    . "User's Drafted Message / Instructions:\n" . ($userDraft ?: "[Empty - please generate a professional reply suggestion]");

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        try {
            $aiReplyText = $this->agentManager->chat($messages, false);
            
            $this->jsonResponse([
                'status' => 'success',
                'suggested_reply' => trim($aiReplyText)
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function streamUnifiedBriefing(int $sessionId, bool $includeUnseen): void
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

        $emit = function($event, $data) {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        };

        $emit('token', ['chunk' => "Syncing connected inboxes (Last 24 Hours Only)...\n\n"]);

        $emailService = new \App\Services\EmailService($this->db);
        $emails = $emailService->fetchRecentEmails($includeUnseen);

        $emailSummaries = [];
        if (empty($emails)) {
            $emailSummaries[] = "No emails found in the last 24 hours across connected accounts.";
        } else {
            $chunks = array_chunk($emails, 5);
            $totalChunks = count($chunks);
            foreach ($chunks as $idx => $chunk) {
                $chunkNum = $idx + 1;
                $emit('token', ['chunk' => "Summarizing email chunk {$chunkNum} of {$totalChunks}...\n\n"]);

                $emailsTxt = "";
                foreach ($chunk as $email) {
                    if (isset($email['error'])) {
                        $emailsTxt .= "Connection Error: " . $email['error'] . "\n";
                    } else {
                        $emailsTxt .= "Email Reference tag: [Email: {$email['account_id']}:{$email['uid']}]\nFrom: " . $email['from'] . "\nSubject: " . $email['subject'] . "\nDate: " . $email['date'] . "\nSnippet: " . $email['snippet'] . "\n\n";
                    }
                }

                $prompt = "Summarize the following emails briefly, listing sender, subject, and key points:\n\n" . $emailsTxt;
                $messages = [
                    ['role' => 'system', 'content' => 'You are an AI assistant.'],
                    ['role' => 'user', 'content' => $prompt]
                ];

                $summary = "";
                $this->agentManager->chat($messages, true, function($token) use (&$summary) {
                    $summary .= $token;
                });
                $emailSummaries[] = $summary;
            }
        }

        $emit('token', ['chunk' => "Retrieving calendar schedules...\n\n"]);

        $todoistSummary = "";
        $tasks = [];
        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            $response = $toolService->makeTodoistRequest('GET', '/tasks');
            $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

            if (empty($tasks)) {
                $todoistSummary = "No active tasks found in the calendar.";
            } else {
                $twoWeeksSeconds = 14 * 24 * 60 * 60;
                $now = time();
                $todayStart = strtotime('today 00:00:00');
                $todayEnd = strtotime('today 23:59:59');
                $upcomingTasks = [];
                $pastTasksToday = [];

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

                    $this->agentManager->chat($messages, true, function($token) use (&$todoistSummary) {
                        $todoistSummary .= $token;
                    });
                }
            }
        } catch (\Throwable $e) {
            $todoistSummary = "Could not retrieve calendar tasks: " . $e->getMessage();
        }

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
        } catch (\Throwable $e) {
        }

        $emit('token', ['chunk' => "Generating final consolidated executive briefing...\n\n"]);

        $currentDate = date('l, F j, Y (H:i)');
        $finalInput = "Here are the summaries of the user's unread emails:\n\n" . implode("\n\n", $emailSummaries)
                    . "\n\nHere is the summary of upcoming calendar tasks for the next two weeks:\n\n" . $todoistSummary;

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
            $finalInput .= "The following suggested calendar events were extracted and cross-referenced by your background sub-agent. You MUST append these exact tags to the very end of your final briefing response so the user can review and click them:\n";
            $finalInput .= $suggestionsTags;
        }

        $finalSystem = "You are a personal executive assistant. Deliver a beautifully structured daily briefing based on the summaries provided. Focus on priority action items, schedule highlights, and status overview. Keep the tone elegant and action-oriented.\n\n"
                     . "Visual Card rule: Append `[Email: account_id:uid]` when mentioning any email.\n"
                     . "Action Card rule: Append any pre-vetted suggested tags (e.g. `[TodoistSuggest: content | due_string]`) at the very end of your final response.";

        $finalMessages = [
            ['role' => 'system', 'content' => $finalSystem],
            ['role' => 'user', 'content' => $finalInput]
        ];

        $finalBriefingText = "";
        $this->agentManager->chat($finalMessages, true, function($token) use ($emit, &$finalBriefingText) {
            $finalBriefingText .= $token;
            $emit('token', ['chunk' => $token]);
        });

        if ($this->db) {
            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role'       => 'assistant',
                'message'    => $finalBriefingText
            ]);
        }
    }

    private function handleToggleStar(): void
    {
        $starSessionId = (int)($_GET['toggle_star'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;

        if ($starSessionId > 0) {
            $isStarred = $this->chatSessionRepository->toggleStar($starSessionId);
            
            $isAjax = (isset($_GET['ajax']) && $_GET['ajax'] == 1) || $this->isApiRequest();
            if ($isAjax) {
                $this->jsonResponse(['status' => 'success', 'is_starred' => $isStarred]);
                return;
            }
            
            $redirectSessionId = (int)($_GET['session_id'] ?? $starSessionId);
            $this->redirect($this->buildUrl($redirectSessionId, $activeTab));
            return;
        }
    }

    private function handleCondenseAction(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid session ID.'], 400);
            return;
        }

        $commit = ($_POST['commit'] ?? '0') === '1';
        $manual = ($_POST['manual'] ?? '0') === '1';

        try {
            $condenser = new ContextCondenser($this->agentManager);
            if ($commit) {
                $summary = $_POST['summary'] ?? '';
                $selectedMemories = $_POST['selected_memories'] ?? [];
                if (!is_array($selectedMemories)) {
                    $selectedMemories = [];
                }
                $result = $condenser->commitCondensation($this->db, $sessionId, $summary, $selectedMemories, $manual);
            } else {
                $result = $condenser->generateCondensationPreview($this->db, $sessionId, $manual);
            }
            $this->jsonResponse($result);
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function streamChatResponse(int $sessionId, string $query, $imageFile, $cacheAction, $cacheKey): void
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

        $searchDecider = new SearchDecider($this->agentManager);
        $cacheEvaluator = new SemanticCacheEvaluator($this->agentManager);
        $contextCondenser = new ContextCondenser($this->agentManager);
        $memorySelectorInstance = $this->db ? new MemorySelector($this->db, $this->agentManager) : null;

        $chatManager = new ChatManager(
            $this->db,
            $this->agentManager,
            $this->memoryExtractor,
            $memorySelectorInstance,
            $searchDecider,
            $cacheEvaluator,
            $contextCondenser
        );

        $chatManager->process($sessionId, $query, $imageFile, $cacheAction, $cacheKey, function($event, $data) {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        });
    }

    private function deleteMultipleSessions(): void
    {
        if (!$this->db) {
            $this->redirect("index.php?new_chat=1");
            return;
        }

        $selectedIds = $_POST['selected_sessions'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->chatSessionRepository->deleteMultiple($selectedIds);
        }
        $this->redirect("index.php?new_chat=1");
    }
}