<?php

namespace App;

use Exception;

class Search
{
    /**
     * Queries SearXNG and returns an array of the top URLs.
     *
     * @param string $query The search query
     * @param int $limit Maximum number of URLs to return
     * @return array Array of URL strings
     */
    public static function query(string $query, int $limit = 3): array
    {
        $host = rtrim(getenv('SEARXNG_HOST') ?: 'http://searxng:8080', '/');
        $url = $host . '/search?q=' . urlencode($query) . '&format=json';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // don't hang forever
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return []; 
        }

        $data = json_decode($response, true);
        if (!isset($data['results']) || !is_array($data['results'])) {
            return [];
        }

        $urls = [];
        foreach ($data['results'] as $result) {
            if (isset($result['url'])) {
                $urls[] = $result['url'];
            }
            if (count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }
}