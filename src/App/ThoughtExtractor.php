<?php

namespace App;

class ThoughtExtractor
{
    private const CHANNEL_THOUGHT_OPEN  = '/<\|[^|]*\|?>thought/';
    private const CHANNEL_THOUGHT_CLOSE = '/<channel\|?>/';
    private const CHANNEL_THOUGHT_PATTERN = '/<\|[^|]*\|?>thought.*?<channel\|?>/s';
    private const THINK_TAG_OPEN   = '/<think>/';
    private const THINK_TAG_CLOSE  = '/<\/think>/';
    private const THINK_TAG_PATTERN = '/<think>.*?<\/think>/s';

    private const CHANNEL_EXTRACT_PATTERN = '/<\|[^|]*\|?>thought(.*?)(?:<channel\|?>|$)/s';
    private const THINK_EXTRACT_PATTERN = '/<think>(.*?)(?:<\/think>|$)/s';

    /**
     * True if this chunk contains the opening of a thought block.
     * Safe to call on individual streaming tokens.
     */
    public static function opensThought(string $chunk): bool
    {
        return (bool) preg_match(self::CHANNEL_THOUGHT_OPEN, $chunk)
            || (bool) preg_match(self::THINK_TAG_OPEN, $chunk);
    }

    /**
     * True if this chunk contains the closing of a thought block.
     * Safe to call on individual streaming tokens.
     */
    public static function closesThought(string $chunk): bool
    {
        return (bool) preg_match(self::CHANNEL_THOUGHT_CLOSE, $chunk)
            || (bool) preg_match(self::THINK_TAG_CLOSE, $chunk);
    }

    /**
     * Check if an accumulated buffer contains a complete thought open tag.
     * Use this instead of opensThought() when tags may span chunk boundaries.
     */
    public static function containsOpenTag(string $buffer): bool
    {
        return (bool) preg_match(self::CHANNEL_THOUGHT_OPEN, $buffer)
            || (bool) preg_match(self::THINK_TAG_OPEN, $buffer);
    }

    /**
     * Check if an accumulated buffer contains a complete thought close tag.
     * Use this instead of closesThought() when tags may span chunk boundaries.
     */
    public static function containsCloseTag(string $buffer): bool
    {
        return (bool) preg_match(self::CHANNEL_THOUGHT_CLOSE, $buffer)
            || (bool) preg_match(self::THINK_TAG_CLOSE, $buffer);
    }

    /**
     * Find the byte position of the first thought open tag in the buffer.
     * Returns the offset, or -1 if no open tag is found.
     */
    public static function openTagPosition(string $buffer): int
    {
        if (preg_match(self::CHANNEL_THOUGHT_OPEN, $buffer, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1];
        }
        if (preg_match(self::THINK_TAG_OPEN, $buffer, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1];
        }
        return -1;
    }

    /**
     * The maximum possible byte length of any thought open tag, used as
     * a buffer threshold — if we haven't seen a tag by this point, none is coming.
     */
    public const MAX_OPEN_TAG_LEN = 20;

    /**
     * The maximum possible byte length of any thought close tag.
     */
    public const MAX_CLOSE_TAG_LEN = 10;

    /**
     * Strip thought blocks from text. Returns clean content-only string.
     */
    public static function strip(string $text): string
    {
        $text = preg_replace(self::CHANNEL_THOUGHT_PATTERN, '', $text);
        $text = preg_replace(self::THINK_TAG_PATTERN, '', $text);
        return trim($text);
    }

    /**
     * Extract thought and content into separate strings.
     * Returns ['thought' => string, 'content' => string].
     */
    public static function extract(string $text): array
    {
        $thought = '';
        $content = $text;

        if (preg_match(self::CHANNEL_EXTRACT_PATTERN, $text, $matches)) {
            $thought = trim($matches[1]);
            $content = trim(preg_replace(self::CHANNEL_THOUGHT_PATTERN, '', $text));
        } elseif (preg_match(self::THINK_EXTRACT_PATTERN, $text, $matches)) {
            $thought = trim($matches[1]);
            $content = trim(preg_replace(self::THINK_TAG_PATTERN, '', $text));
        }

        return ['thought' => $thought, 'content' => $content];
    }
}
