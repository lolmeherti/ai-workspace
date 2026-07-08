<?php

namespace App;

use Exception;
use Throwable;

class Logger
{
    public const INFO = 'INFO';
    public const WARN = 'WARN';
    public const ERROR = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    private static ?string $logFile = null;
    private static ?Database $db = null;

    public static function setDatabase(Database $db): void
    {
        self::$db = $db;
    }

    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            self::$logFile = Config::getProjectRoot() . '/logs/app.log';
            
            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        return self::$logFile;
    }

    public static function logEvent(string $eventType, string $message, array $context = [], string $level = 'info', ?string $source = null): void
    {
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $fullMessage = $message . $contextStr;

        if (self::$db !== null) {
            try {
                self::$db->insert('app_events', [
                    'event_type' => $eventType,
                    'message' => mb_substr($fullMessage, 0, 65535),
                    'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'level' => $level,
                    'source' => $source,
                ]);
            } catch (Throwable $e) {
                // DB write failed — fall through to file log only
                $fullMessage .= "\n[Logger DB write failed: " . $e->getMessage() . ']';
            }
        }

        // Always write to file log as backup
        self::writeFile($level, $fullMessage);
    }

    private static function writeFile(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n" . str_repeat('-', 80) . "\n";
        file_put_contents(self::getLogFile(), $logEntry, FILE_APPEND);
    }

    public static function log(string $level, string $message, array $context = [], ?Throwable $exception = null): void
    {
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        
        $logEntry = "[{$level}] {$message}{$contextStr}";

        if ($exception !== null) {
            $logEntry .= "\nException: " . $exception->getMessage() . "\nStack Trace:\n" . $exception->getTraceAsString();
        } elseif ($level === self::ERROR || $level === self::CRITICAL) {
            $logEntry .= "\nStack Trace:\n" . (new Exception())->getTraceAsString();
        }

        // DB event (auto-derived event_type from level)
        $dbContext = $context;
        if ($exception !== null) {
            $dbContext['exception'] = $exception->getMessage();
            $dbContext['trace'] = $exception->getTraceAsString();
        } elseif ($level === self::ERROR || $level === self::CRITICAL) {
            $dbContext['trace'] = (new Exception())->getTraceAsString();
        }

        self::logEvent(
            strtolower($level),
            $message,
            $dbContext,
            strtolower($level)
        );

        // File log (existing behavior preserved)
        $timestamp = date('Y-m-d H:i:s');
        $fileEntry = "[{$timestamp}] {$logEntry}\n" . str_repeat('-', 80) . "\n";
        file_put_contents(self::getLogFile(), $fileEntry, FILE_APPEND);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log(self::WARN, $message, $context);
    }

    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::log(self::ERROR, $message, $context, $exception);
    }

    public static function critical(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::log(self::CRITICAL, $message, $context, $exception);
    }
}
