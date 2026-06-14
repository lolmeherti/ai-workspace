<?php

namespace App;

class JsonParser
{
    public static function extractAndDecode(string $text): ?array
    {
        if (preg_match('/(?:call|tool_call):([a-zA-Z0-9_\-]+)\s*(\{.*\})/is', $text, $matches)) {
            $toolName = trim($matches[1]);
            $jsonStr = trim($matches[2]);
            $decoded = @json_decode($jsonStr, true);
            if (is_array($decoded)) {
                $decoded['tool'] = $toolName;
                return self::normalizeOutput($decoded);
            }
            self::logError($text, "Tool call JSON failed: " . json_last_error_msg());
        }

        $startIndex = strpos($text, '{');
        $isObject = true;
        
        $bracketIndex = strpos($text, '[');
        if ($startIndex === false && $bracketIndex === false) {
            return self::fallbackDecode($text);
        }
        
        if ($startIndex === false || ($bracketIndex !== false && $bracketIndex < $startIndex)) {
            $startIndex = $bracketIndex;
            $isObject = false;
        }

        if ($isObject) {
            $firstQuote = strpos($text, '"', $startIndex);
            if ($firstQuote !== false) {
                $secondQuote = strpos($text, '"', $firstQuote + 1);
                if ($secondQuote !== false) {
                    $colon = strpos($text, ':', $secondQuote + 1);
                    if ($colon !== false) {
                        $remaining = substr($text, $colon + 1);
                        if (preg_match('/^\s*([{"\[\d\w])/', $remaining)) {
                            $endIndex = strrpos($text, '}');
                            if ($endIndex !== false && $endIndex > $startIndex) {
                                $jsonStr = substr($text, $startIndex, $endIndex - $startIndex + 1);
                                $decoded = @json_decode($jsonStr, true);
                                if (is_array($decoded)) {
                                    return self::normalizeOutput($decoded);
                                }
                                self::logError($text, "Object extraction failed: " . json_last_error_msg());
                            }
                        }
                    }
                }
            }
        } else {
            $endIndex = strrpos($text, ']');
            if ($endIndex !== false && $endIndex > $startIndex) {
                $jsonStr = substr($text, $startIndex, $endIndex - $startIndex + 1);
                $decoded = @json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    return self::normalizeOutput($decoded);
                }
                self::logError($text, "Array extraction failed: " . json_last_error_msg());
            }
        }

        return self::fallbackDecode($text);
    }

    private static function normalizeOutput(array $decoded): array
    {
        if (isset($decoded['name']) && isset($decoded['arguments']) && is_array($decoded['arguments'])) {
            $normalized = $decoded['arguments'];
            $normalized['tool'] = $decoded['name'];
            return $normalized;
        }
        if (isset($decoded['tool_call']) && is_array($decoded['tool_call'])) {
            $tc = $decoded['tool_call'];
            if (isset($tc['name']) && isset($tc['arguments']) && is_array($tc['arguments'])) {
                $normalized = $tc['arguments'];
                $normalized['tool'] = $tc['name'];
                return $normalized;
            }
        }
        return $decoded;
    }

    private static function logError(string $text, string $error): void
    {
        $logFile = \App\Config::getProjectRoot() . '/json_parser_errors.log';
        $date = date('Y-m-d H:i:s');
        
        $logEntry = <<<TEXT
================ JSON PARSER ERROR ================
Date: {$date}
Error: {$error}
Input: 
{$text}
==================================================

TEXT;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    private static function fallbackDecode(string $text): ?array
    {
        if (strpos($text, '```') !== false) {
            $cleaned = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $text);
            $decoded = @json_decode(trim($cleaned), true);
            if (is_array($decoded)) {
                return self::normalizeOutput($decoded);
            }
            self::logError($text, "Markdown JSON failed: " . json_last_error_msg());
        }

        $decoded = @json_decode(trim($text), true);
        if (is_array($decoded)) {
            return self::normalizeOutput($decoded);
        }
        
        if ($text !== '' && $text !== 'null') {
             self::logError($text, "Final fallback failed: " . json_last_error_msg());
        }

        return null;
    }
}