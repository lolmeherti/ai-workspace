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

    public static function log(string $level, string $message, array $context = [], ?Throwable $exception = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}";

        if ($exception !== null) {
            $logEntry .= "\nException: " . $exception->getMessage() . "\nStack Trace:\n" . $exception->getTraceAsString();
        } elseif ($level === self::ERROR || $level === self::CRITICAL) {
            $logEntry .= "\nStack Trace:\n" . (new Exception())->getTraceAsString();
        }

        $logEntry .= "\n" . str_repeat('-', 80) . "\n";

        file_put_contents(self::getLogFile(), $logEntry, FILE_APPEND);
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
