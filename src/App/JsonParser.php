<?php

namespace App;

use App\Enums\Tool;

class JsonParser
{
    public static function extractAllAndDecode(string $text): array
    {
        $results = self::extractStrictJsonObjects($text);
        if (!empty($results)) {
            return $results;
        }

        $reconstructed = self::reconstructToolCalls($text);
        if (!empty($reconstructed)) {
            return $reconstructed;
        }

        return [];
    }

    public static function extractAndDecode(string $text): ?array
    {
        $objects = self::extractStrictJsonObjects($text);
        if (!empty($objects)) {
            return $objects[0];
        }

        $reconstructed = self::reconstructToolCalls($text);
        if (!empty($reconstructed)) {
            return $reconstructed[0];
        }

        return null;
    }

    public static function stripToolCallArtifacts(string $text): string
    {
        $toolNames = self::getToolNamePattern();

        $text = preg_replace(
            '/(?:<\|?)?tool_call(?:\|?>|>)?\s*call:(?:[a-zA-Z0-9_-]+(?:\:|\.))?[a-zA-Z0-9_-]+(?:\s*[\{\(][\s\S]*?[\}\)])?\s*(?:<\|?)?tool_call(?:\|?>|>)?/is',
            '',
            $text
        );

        $text = preg_replace(
            '/\bcall:(?:[a-zA-Z0-9_-]+(?:\:|\.))?(?:' . $toolNames . ')(?:\s*[\{\(][\s\S]*?[\}\)])?/i',
            '',
            $text
        );

        $text = preg_replace(
            '/(?<!\w):[a-zA-Z0-9_-]+(?:\:|\.)(?:' . $toolNames . ')(?:\s*[\{\(][\s\S]*?[\}\)])?/i',
            '',
            $text
        );

        $text = preg_replace(
            '/(?:"tool"|tool)\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\b(?:' . $toolNames . ')\b)\s*\{[\s\S]*?\}/is',
            '',
            $text
        );

        return trim($text);
    }

    private static function getToolNamePattern(): string
    {
        return implode('|', array_map(fn(Tool $case) => preg_quote($case->value, '/'), Tool::cases()));
    }

    private static function getParamNamePattern(): string
    {
        return 'query|content|due_string|new_content|new_due_string';
    }

    private static function extractStrictJsonObjects(string $text): array
    {
        $results = [];
        $len = strlen($text);
        $braceCount = 0;
        $start = -1;

        for ($i = 0; $i < $len; $i++) {
            if ($text[$i] === '{') {
                if ($braceCount === 0) {
                    $start = $i;
                }
                $braceCount++;
            } elseif ($text[$i] === '}') {
                if ($braceCount > 0) {
                    $braceCount--;
                    if ($braceCount === 0 && $start !== -1) {
                        $substring = substr($text, $start, $i - $start + 1);
                        if (preg_match('/^\{\s*"/', $substring)) {
                            $decoded = @json_decode($substring, true);
                            if (is_array($decoded)) {
                                $results[] = self::normalizeOutput($decoded);
                            }
                        }
                    }
                }
            }
        }

        return $results;
    }

    private static function reconstructToolCalls(string $text): array
    {
        $results = [];
        $seen = [];
        $toolNames = self::getToolNamePattern();
        $paramNames = self::getParamNamePattern();

        if (preg_match_all('/\b(' . $toolNames . ')\b/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $toolName = $match[0];
                $argsBlock = self::findArgsBlock($text, $match[1] + strlen($toolName));
                $args = $argsBlock !== null ? self::parsePseudoObject($argsBlock, $paramNames) : [];
                $args['tool'] = $toolName;
                $hash = md5(json_encode($args));
                if (!in_array($hash, $seen, true)) {
                    $seen[] = $hash;
                    $results[] = $args;
                }
            }
        }

        if (preg_match_all('/(?:"tool"|tool)\s*:\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\b(?:' . $toolNames . ')\b)/is', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $toolName = trim($match[0], "\"'");
                $toolName = preg_replace('/^(?:"tool"|tool)\s*:\s*/i', '', $toolName);
                $toolName = trim($toolName, "\"'");
                if (!in_array($toolName, array_map(fn(Tool $case) => $case->value, Tool::cases()), true)) {
                    continue;
                }

                $argsBlock = self::findArgsBlock($text, $match[1] + strlen($match[0]));
                $args = $argsBlock !== null ? self::parsePseudoObject($argsBlock, $paramNames) : [];
                $args['tool'] = $toolName;
                $hash = md5(json_encode($args));
                if (!in_array($hash, $seen, true)) {
                    $seen[] = $hash;
                    $results[] = $args;
                }
            }
        }

        return $results;
    }

    private static function findArgsBlock(string $text, int $startPos, int $maxDistance = 100): ?string
    {
        $openBrace = strpos($text, '{', $startPos);
        $openParen = strpos($text, '(', $startPos);
        $candidates = [];

        if ($openBrace !== false && $openBrace - $startPos <= $maxDistance) {
            $candidates[] = ['pos' => $openBrace, 'open' => '{', 'close' => '}'];
        }
        if ($openParen !== false && $openParen - $startPos <= $maxDistance) {
            $candidates[] = ['pos' => $openParen, 'open' => '(', 'close' => ')'];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => $a['pos'] <=> $b['pos']);
        $chosen = $candidates[0];

        $count = 0;
        $len = strlen($text);
        for ($i = $chosen['pos']; $i < $len; $i++) {
            if ($text[$i] === $chosen['open']) {
                $count++;
            } elseif ($text[$i] === $chosen['close']) {
                $count--;
                if ($count === 0) {
                    return substr($text, $chosen['pos'], $i - $chosen['pos'] + 1);
                }
            }
        }

        return null;
    }

    private static function parsePseudoObject(string $text, string $paramNames): array
    {
        $args = [];
        $pattern = '/\b(' . $paramNames . ')\b\s*:\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/is';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2];
                $quote = $value[0];
                $value = substr($value, 1, -1);
                $value = $quote === '"' ? str_replace('\"', '"', $value) : str_replace("\\'", "'", $value);
                $args[$key] = $value;
            }
        }

        return $args;
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
}
