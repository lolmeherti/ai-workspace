<?php

namespace App;

class ProgressWriter
{
    private static string $dir = '';

    public static function init(string $baseDir): void
    {
        $dir = $baseDir . '/progress';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            self::$dir = $dir;
            Logger::logEvent('progress_init', "ProgressWriter ready at {$dir}", [], 'info', 'ProgressWriter');
        } else {
            Logger::logEvent('progress_init_failed', "ProgressWriter: dir not writable — {$dir}", [], 'warn', 'ProgressWriter');
        }
    }

    public static function isReady(): bool
    {
        return self::$dir !== '';
    }

    public static function write(int $sessionId, string $event, string $label, string $color = 'slate', string $url = ''): void
    {
        if (empty(self::$dir)) return;

        $payload = compact('event', 'label', 'color');
        if ($url !== '') {
            $payload['url'] = $url;
        }
        $line = json_encode($payload);
        if ($line === false) {
            Logger::logEvent('progress_json_failed', "json_encode failed for event={$event}", [], 'error', 'ProgressWriter');
            return;
        }

        $path = self::$dir . '/' . $sessionId . '.jsonl';
        $result = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

        if ($result === false) {
            Logger::logEvent('progress_write_failed', "file_put_contents failed for {$path}", [
                'event' => $event,
                'label_preview' => substr($label, 0, 80),
            ], 'error', 'ProgressWriter');
        }
    }

    public static function done(int $sessionId): void
    {
        self::write($sessionId, 'done', '', '');
    }
}
