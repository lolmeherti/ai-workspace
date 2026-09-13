<?php

namespace App\Actions;

/**
 * Accepts a frontend-reported event (stream truncation, render exception,
 * manual "report this answer", …) and writes it into the app_events log so
 * the last-mile of a chat turn — "did the full answer actually reach and
 * render in the browser" — is observable alongside backend events.
 *
 * Payload (JSON body):
 *   type        string  event_type, e.g. 'stream_truncated', 'render_exception'
 *   message     string  human-readable summary
 *   level       string  info|warn|error|critical (default warn)
 *   session_id  int     optional chat session to associate the event with
 *   context     object  optional arbitrary fields to store as event context
 */
class LogFrontendEventAction extends BaseAction
{
    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid payload.'], 400);
            return;
        }

        $type = isset($input['type']) && is_string($input['type']) && $input['type'] !== ''
            ? $input['type']
            : 'frontend_event';

        $message = isset($input['message']) && is_string($input['message'])
            ? $input['message']
            : $type;

        $level = 'warn';
        if (isset($input['level']) && in_array($input['level'], ['info', 'warn', 'error', 'critical'], true)) {
            $level = $input['level'];
        }

        $sessionId = null;
        if (isset($input['session_id'])) {
            $sid = (int) $input['session_id'];
            if ($sid > 0) {
                $sessionId = $sid;
            }
        }

        $context = is_array($input['context'] ?? null) ? $input['context'] : [];

        \App\Logger::setSessionId($sessionId);
        try {
            \App\Logger::logEvent($type, $message, $context, $level, 'frontend');
        } finally {
            \App\Logger::clearSessionId();
        }

        $this->jsonResponse(['status' => 'ok']);
    }
}
