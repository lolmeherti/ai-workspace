<?php

namespace App\Jobs;

class JobMatcher
{
    private const LEGAL_SUFFIXES = [
        'gmbh', 'gesmbh', 'ag', 'kg', 'ltd', 'limited',
        'inc', 'incorporated', 'llc', 'corp', 'corporation',
        'co', 'se', 'sa', 'bv', 'nv', 'oy', 'sarl', 'sas', 'plc',
    ];

    public static function normalizeCompany(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $lower = preg_replace('/[^\p{L}\p{N}\s]/u', '', $lower);
        $words = preg_split('/\s+/u', $lower);
        $words = array_values(array_filter($words, fn($word) => $word !== ''));

        while (!empty($words) && in_array(end($words), self::LEGAL_SUFFIXES, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    public static function normalizeDomain(string $domain): string
    {
        $lower = mb_strtolower(trim($domain));
        $lower = preg_replace('#^https?://#', '', $lower);
        $lower = preg_replace('#^www\.#', '', $lower);
        return rtrim($lower, '/');
    }

    public static function isCompanyBlocked(array $blockedCompanies, string $company): bool
    {
        $target = self::normalizeCompany($company);
        if ($target === '') {
            return false;
        }

        foreach ($blockedCompanies as $blocked) {
            $norm = self::normalizeCompany((string)$blocked);
            if ($norm === '') {
                continue;
            }
            if (str_contains($target, $norm) || str_contains($norm, $target)) {
                return true;
            }
        }

        return false;
    }

    public static function isDomainBlocked(array $blockedDomains, string $domain): bool
    {
        $target = self::normalizeDomain($domain);
        foreach ($blockedDomains as $blocked) {
            if (self::normalizeDomain((string)$blocked) === $target) {
                return true;
            }
        }
        return false;
    }

    public static function isDuplicate(string $url, string $postedAt, array $existing): bool
    {
        foreach ($existing as $row) {
            if (($row['url'] ?? '') === $url && ($row['posted_at'] ?? '') === $postedAt) {
                return true;
            }
        }
        return false;
    }

    public static function isStale(string $postedAt, int $days = 14): bool
    {
        $timestamp = strtotime($postedAt);
        if ($timestamp === false) {
            return true;
        }
        return $timestamp < strtotime("-{$days} days");
    }
}
