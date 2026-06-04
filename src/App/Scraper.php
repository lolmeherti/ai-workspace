<?php

namespace App;

use DOMDocument;

class Scraper
{
    /**
     * Fetches a webpage via FlareSolverr, cleans it, and truncates it.
     *
     * @param string $targetUrl The URL to scrape
     * @return string The cleaned, truncated text
     */
    public static function fetchAndClean(string $targetUrl): string
    {
        $flareHost = rtrim(getenv('FLARESOLVERR_HOST') ?: 'http://flaresolverr:8191', '/');
        $endpoint = $flareHost . '/v1';

        $payload = json_encode([
            "cmd" => "request.get",
            "url" => $targetUrl,
            "maxTimeout" => 15000 // 15 seconds max
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return ""; 
        }

        $data = json_decode($response, true);
        if (!isset($data['solution']['response'])) {
            return "";
        }

        $html = $data['solution']['response'];

        return self::cleanAndTruncate($html);
    }

    /**
     * Surgically removes layout tags, strips HTML, and enforces the token limit.
     */
    private static function cleanAndTruncate(string $html): string
    {
        if (empty(trim($html))) {
            return "";
        }

        $dom = new DOMDocument();
        
        libxml_use_internal_errors(true);
        
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        libxml_clear_errors();

        $tagsToRemove = [
            // Layout & Boilerplate
            'script', 'style', 'nav', 'header', 'footer', 
            'aside', 'menu', 'noscript', 'form', 
            // Media & Embeds (New additions)
            'img', 'video', 'picture', 'source', 'svg', 
            'canvas', 'embed', 'object', 'iframe', 'audio', 'track'
        ];

        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $node = $elements->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $cleanedHtml = $dom->saveHTML();

        $text = strip_tags($cleanedHtml);

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $maxTokens = (int)(getenv('MAX_SCRAPE_TOKENS') ?: 2500);
        $maxCharacters = $maxTokens * 4;

        if (mb_strlen($text) > $maxCharacters) {
            $text = mb_substr($text, 0, $maxCharacters) . '... [TRUNCATED]';
        }

        return $text;
    }
}