<?php

namespace App;

class JsonParser
{
    /**
     * Robustly extracts and decodes the first valid JSON block (object or array) found in a string.
     */
    public static function extractAndDecode(string $text): ?array
    {
        // 1. Determine if we are parsing an object '{' or array '['
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
            // Find the first double quotes '"' after '{'
            $firstQuote = strpos($text, '"', $startIndex);
            if ($firstQuote !== false) {
                // Find the closing key quote '"'
                $secondQuote = strpos($text, '"', $firstQuote + 1);
                if ($secondQuote !== false) {
                    // Find the colon ':'
                    $colon = strpos($text, ':', $secondQuote + 1);
                    if ($colon !== false) {
                        // Find the space and opening mark (e.g., ", {, [, digit, or word)
                        $remaining = substr($text, $colon + 1);
                        if (preg_match('/^\s*([{"\[\d\w])/', $remaining)) {
                            // Start verified! Find matching closing brace from the end
                            $endIndex = strrpos($text, '}');
                            if ($endIndex !== false && $endIndex > $startIndex) {
                                $jsonStr = substr($text, $startIndex, $endIndex - $startIndex + 1);
                                $decoded = @json_decode($jsonStr, true);
                                if (is_array($decoded)) {
                                    return $decoded;
                                }
                            }
                        }
                    }
                }
            }
        } else {
            // It is a bracketed array '['
            $endIndex = strrpos($text, ']');
            if ($endIndex !== false && $endIndex > $startIndex) {
                $jsonStr = substr($text, $startIndex, $endIndex - $startIndex + 1);
                $decoded = @json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return self::fallbackDecode($text);
    }

    /**
     * Fallback clean-up using regex backtick extraction and raw decode.
     */
    private static function fallbackDecode(string $text): ?array
    {
        if (strpos($text, '```') !== false) {
            $cleaned = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $text);
            $decoded = @json_decode(trim($cleaned), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $decoded = @json_decode(trim($text), true);
        return is_array($decoded) ? $decoded : null;
    }
}