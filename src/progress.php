<?php

$sessionId = (int)($_GET['session'] ?? 0);
if ($sessionId <= 0) { http_response_code(400); exit; }

$file = '/tmp/progress/' . $sessionId . '.jsonl';
$logFile = '/tmp/progress/' . $sessionId . '.log';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo ":ok\n\n";
ob_flush(); flush();

file_put_contents($logFile, date('H:i:s') . " started — reading {$file}\n", FILE_APPEND);

$lastPos = 0;
$timeout = 180;
$started = time();
$lineCount = 0;
$skipCount = 0;

while (true) {
    if (time() - $started > $timeout) break;

    clearstatcache(true, $file);
    $size = @filesize($file);

    if ($size !== false && $size > $lastPos) {
        $f = @fopen($file, 'r');
        if ($f) {
            fseek($f, $lastPos);
            while (($line = fgets($f)) !== false) {
                $raw = trim($line);
                file_put_contents($logFile, date('H:i:s') . " raw[" . strlen($raw) . "]: " . substr($raw, 0, 120) . "\n", FILE_APPEND);

                if ($raw === '') {
                    file_put_contents($logFile, "  -> skipped (empty)\n", FILE_APPEND);
                    continue;
                }
                $data = json_decode($raw, true);
                if (!$data) {
                    $skipCount++;
                    file_put_contents($logFile, "  -> SKIPPED json_error=" . json_last_error_msg() . "\n", FILE_APPEND);
                    continue;
                }

                $lineCount++;
                echo "data: " . json_encode($data) . "\n\n";
                ob_flush(); flush();

                if (($data['event'] ?? '') === 'done') {
                    file_put_contents($logFile, date('H:i:s') . " DONE — {$lineCount} sent, {$skipCount} skipped\n", FILE_APPEND);
                    fclose($f);
                    @unlink($file);
                    @unlink($logFile);
                    exit;
                }
            }
            $lastPos = ftell($f);
            fclose($f);
        }
    }

    sleep(1);
}

file_put_contents($logFile, date('H:i:s') . " TIMEOUT — {$lineCount} sent, {$skipCount} skipped\n", FILE_APPEND);
@unlink($file);
