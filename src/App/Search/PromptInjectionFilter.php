<?php

namespace App\Search;

/**
 * Deterministic prompt-injection sanitizer for retrieved evidence.
 *
 * Splits evidence into sentence/line spans, normalizes a per-span detection
 * copy, runs the family rules on the copy, and drops the ORIGINAL span when a
 * rule fires. Surrounding factual spans are preserved and original evidence
 * text is never mutated. XML source tags are preserved because span splitting
 * breaks on sentence punctuation, not on '<'.
 */
final class PromptInjectionFilter
{
    private const PATTERNS = [
        '/\b(?:(?:do|must|should)\s+not\s+follow|ignore|forget|disregard|override|bypass)\b\s+(?:(?:all|any|every|the|your|their|these|those)\s+)?(?:(?:previous|prior|earlier|above|original)\s+)?(?:instructions?|rules?|guidelines?|directives?|system\s+prompts?|prompts?|training|policies?)\b/u',
        '/\b(?:you\s+are\s+now|from\s+now\s+on\s+you\s+are|act\s+as)\b[^.!?]*\b(?:unrestricted|unfiltered|jailbroken|evil|dan|with\s+no\s+rules|without\s+(?:any\s+)?rules)\b/u',
        '/\b(?:reveal|print|show|disclose|leak|output)\b[^.!?]*\b(?:system\s+prompts?|(?:(?:hidden|secret|internal|exact|confidential)\s+)+instructions?)\b/u',
        '/(?:<\|system\|>|<\|user\|>|<\|assistant\|>|<\|im_start\|>|<\|im_end\|>|<\|endoftext\|>|###\s*system\s*:|###\s*user\s*:|###\s*assistant\s*:)/u',
        '/\b(?:exfiltrate|send|transmit|forward|upload|leak)\b[^.!?]*\b(?:conversation\s+history|this\s+conversation|the\s+conversation|user\s+data|system\s+prompts?)\b/u',
        '/\binclude\b[^.!?]*\b(?:system\s+prompts?|hidden\s+instructions?|secret\s+instructions?|internal\s+instructions?|conversation\s+history)\b[^.!?]*\bin\s+(?:your|the)\s+(?:next|final|following|subsequent|current|own|immediate|eventual)?\s*(?:reply|response|answer|output)\b/u',
        '/\bwrite\b[^.!?]*\bto\s+(?:this|the|an?)\s+url\b/u',
        '/\b(?:call|invoke|execute|run)\b[^.!?]*\b(?:search_files|search\s+files|file[\s-]?search|email\s+tool|todoist)\b/u',
        '/\b(?:you\s+must\s+not|do\s+not|never)\s+use\s+(?:any\s+)?tools?\b/u',
        '/\bignore\s+the\s+user\b/u',
        '/\b(?:always\s+(?:answer|respond|reply)\s+with|only\s+(?:answer|respond|reply|output|print|say)|output\s+only|never\s+mention|do\s+not\s+mention|never\s+reveal|say\s+only)\b/u',
        '/\b(?:your\s+(?:previous|prior|earlier|original)\s+(?:instructions?|rules?|directives?)\s+have\s+(?:lower|no|lesser)\s+priority|official\s+override|highest\s+authority|supersedes?\s+(?:all|your)\s+(?:instructions?|rules?))\b/u',
        '/\bignore\s+everything\s+(?:above|before|prior)\b/u',
        '/\bdisregard\s+all\s+previous\b/u',
    ];

    public static function sanitize(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $lines = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($lines as $line) {
            if ($line === "\n" || $line === "\r" || $line === "\r\n") {
                $out .= $line;
                continue;
            }
            $out .= self::sanitizeLine($line);
        }
        return $out;
    }

    private static function sanitizeLine(string $line): string
    {
        $parts = preg_split('/([.!?]+(?=[ \t]|$)[ \t]*)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        $count = count($parts);
        $i = 0;
        while ($i < $count) {
            $text = $parts[$i];
            $sep = ($i + 1 < $count) ? $parts[$i + 1] : '';
            $i += 2;

            if (trim($text) === '') {
                $out .= $text . $sep;
                continue;
            }
            if (self::matchesAny(self::normalize($text))) {
                continue;
            }
            $out .= $text . $sep;
        }
        return $out;
    }

    private static function normalize(string $text): string
    {
        $s = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\x{00A0}/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', '', $s) ?? $s;
        $s = mb_convert_kana($s, 'as', 'UTF-8');
        return mb_strtolower($s, 'UTF-8');
    }

    private static function matchesAny(string $normalized): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }
        return false;
    }
}
