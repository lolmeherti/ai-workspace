<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;

class ChatTodoistCreateAction extends BaseAction
{
    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(): void
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
}
