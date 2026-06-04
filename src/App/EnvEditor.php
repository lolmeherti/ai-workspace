<?php

namespace App;

class EnvEditor
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function read(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $vars[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $vars;
    }

    public function write(array $newVars): bool
    {
        if (!file_exists($this->filePath)) {
            return false;
        }
        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        $updatedLines = [];
        $processedKeys = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || str_starts_with($trimmed, '#')) {
                $updatedLines[] = $line;
                continue;
            }
            $parts = explode('=', $trimmed, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                if (array_key_exists($key, $newVars)) {
                    $updatedLines[] = "{$key}=" . $newVars[$key];
                    $processedKeys[$key] = true;
                } else {
                    $updatedLines[] = $line;
                }
            } else {
                $updatedLines[] = $line;
            }
        }

        foreach ($newVars as $key => $value) {
            if (!isset($processedKeys[$key])) {
                $updatedLines[] = "{$key}={$value}";
            }
        }

        return file_put_contents($this->filePath, implode("\n", $updatedLines) . "\n") !== false;
    }
}