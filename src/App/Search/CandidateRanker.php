<?php

namespace App\Search;

final class CandidateRanker
{
    // Platforms unlikely to yield substantial extractable text from a normal page.
    // Strongly deprioritized, not blocked — they win scrape slots only when nothing
    // better surfaces.
    private const DEPRIORITIZED_DOMAINS = [
        // Video / social media-first
        'youtube.com', 'youtu.be', 'tiktok.com', 'twitch.tv', 'vimeo.com',
        'dailymotion.com', 'rumble.com', 'bitchute.com', 'kick.com', 'bilibili.com',
        'snapchat.com', 'threads.net',
        // Image-first / stock
        'instagram.com', 'pinterest.com', 'imgur.com', 'flickr.com', 'behance.net',
        'dribbble.com', 'unsplash.com', 'pexels.com', 'shutterstock.com',
        'deviantart.com', '9gag.com', 'gettyimages.com', 'istockphoto.com', 'pixabay.com',
        // Audio-first
        'spotify.com', 'soundcloud.com', 'bandcamp.com', 'deezer.com', 'audiomack.com',
        'podcasts.apple.com', 'music.apple.com',
        // Account-gated / poorly publicly browsable
        'x.com', 'twitter.com', 'facebook.com', 'linkedin.com', 'glassdoor.com',
        'patreon.com', 'nextdoor.com', 't.me',
    ];

    /**
     * Sort candidates so deprioritized domains (video/image/audio-first, account-gated)
     * come last, preserving relative order within each group. They are not blocked —
     * they fill scrape slots only after normal extractable pages.
     *
     * @param Candidate[] $candidates
     * @return Candidate[]
     */
    public static function deprioritize(array $candidates): array
    {
        $regular = [];
        $fallback = [];
        foreach ($candidates as $c) {
            if (self::isDeprioritizedDomain($c->domain)) {
                $fallback[] = $c;
            } else {
                $regular[] = $c;
            }
        }
        return array_merge($regular, $fallback);
    }

    private static function isDeprioritizedDomain(string $domain): bool
    {
        $domain = strtolower($domain);
        foreach (self::DEPRIORITIZED_DOMAINS as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) {
                return true;
            }
        }
        return false;
    }
}
