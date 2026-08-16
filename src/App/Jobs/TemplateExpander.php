<?php

namespace App\Jobs;

class TemplateExpander
{
    public const PLACEHOLDERS = ['job_title', 'location'];

    public static function expand(array $entry): array
    {
        $template = trim((string)($entry['url'] ?? ''));
        if ($template === '') {
            return [];
        }

        $placeholders = $entry['placeholders'] ?? [];
        if (!is_array($placeholders)) {
            $placeholders = [];
        }

        $tokens = self::tokensIn($template);
        if ($tokens === []) {
            return [$template];
        }

        $dimensions = [];
        foreach ($tokens as $token) {
            $values = self::cleanValues($placeholders[$token] ?? []);
            if ($values === []) {
                return [];
            }
            $dimensions[] = $values;
        }

        $urls = [];
        foreach (self::cartesian($dimensions) as $combo) {
            $url = $template;
            foreach ($tokens as $i => $token) {
                $url = str_replace('{' . $token . '}', rawurlencode($combo[$i]), $url);
            }
            $urls[] = $url;
        }
        return $urls;
    }

    private static function tokensIn(string $template): array
    {
        $tokens = [];
        foreach (self::PLACEHOLDERS as $name) {
            if (str_contains($template, '{' . $name . '}')) {
                $tokens[] = $name;
            }
        }
        return $tokens;
    }

    private static function cleanValues(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }
        return $out;
    }

    private static function cartesian(array $dimensions): array
    {
        $result = [[]];
        foreach ($dimensions as $dim) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($dim as $value) {
                    $next[] = array_merge($combo, [$value]);
                }
            }
            $result = $next;
        }
        return $result;
    }
}
