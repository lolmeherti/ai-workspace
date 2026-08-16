<?php

namespace App\Database;

class FileAliasMap
{
    private const ALIASES = [
        'cv'               => ['cv', 'resume', 'curriculum vitae', 'lebenslauf'],
        'resume'           => ['cv', 'resume', 'curriculum vitae', 'lebenslauf'],
        'curriculum vitae' => ['cv', 'resume', 'curriculum vitae', 'lebenslauf'],
        'lebenslauf'       => ['cv', 'resume', 'curriculum vitae', 'lebenslauf'],
        'röntgen'          => ['röntgen', 'x-ray', 'xray', 'x_ray'],
        'x-ray'            => ['röntgen', 'x-ray', 'xray', 'x_ray'],
        'xray'             => ['röntgen', 'x-ray', 'xray', 'x_ray'],
        'befund'           => ['befund', 'medical report', 'clinical report'],
        'medical report'   => ['befund', 'medical report', 'clinical report'],
        'rechnung'         => ['rechnung', 'invoice', 'bill'],
        'invoice'          => ['rechnung', 'invoice', 'bill'],
        'bill'             => ['rechnung', 'invoice', 'bill'],
    ];

    public function expand(string $query): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
        if ($normalized === '') {
            return $query;
        }

        $added = [];
        foreach (self::ALIASES as $key => $synonyms) {
            if (!$this->containsTerm($normalized, $key)) {
                continue;
            }
            foreach ($synonyms as $synonym) {
                if (!$this->containsTerm($normalized, $synonym)) {
                    $added[$synonym] = true;
                }
            }
        }

        if (empty($added)) {
            return $query;
        }

        return $query . ' ' . implode(' ', array_keys($added));
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        return (bool) preg_match(
            '/(?<!\p{L})' . preg_quote($term, '/') . '(?!\p{L})/u',
            $haystack
        );
    }
}
