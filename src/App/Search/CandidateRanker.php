<?php

namespace App\Search;

use App\AgentManager;
use App\Enums\SearchIntent;

final class CandidateRanker
{
    private AgentManager $agent;

    // Domain preference boost by intent
    private const DOMAIN_PREFERENCES = [
        'product_specs' => [
            'apple.com' => 2.0, 'samsung.com' => 2.0, 'gsmarena.com' => 1.5,
            'dpreview.com' => 1.5, 'notebookcheck.net' => 1.0,
        ],
        'software_docs' => [
            'github.com' => 2.0, 'gitlab.com' => 1.5, 'stackoverflow.com' => 1.0,
            'npmjs.com' => 1.0, 'packagist.org' => 1.0, 'pypi.org' => 1.0,
            'docs.rs' => 1.0, 'devdocs.io' => 1.0,
        ],
        'news' => [
            'reuters.com' => 2.0, 'apnews.com' => 2.0, 'bbc.com' => 1.5,
            'arstechnica.com' => 1.0, 'theverge.com' => 1.0, 'techcrunch.com' => 1.0,
        ],
        'academic' => [
            'arxiv.org' => 2.0, 'pubmed.ncbi.nlm.nih.gov' => 2.0,
            'scholar.google.com' => 1.0, 'researchgate.net' => 0.5,
            'semanticscholar.org' => 1.0,
        ],
    ];

    // Low-quality domains — configurable blocklist
    private const LOW_QUALITY_DOMAINS = [
        'pinterest.com', 'quora.com', 'medium.com',
    ];

    private const LOW_QUALITY_PENALTY = 5.0;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Score candidates deterministically. Called before any page fetch.
     *
     * @param Candidate[] $candidates
     * @param string $question
     * @param SearchIntent $intent
     * @param array<string> $searchTerms Tokenized search terms
     * @return Candidate[] scored candidates, sorted by score descending
     */
    public function scoreDeterministic(array $candidates, string $question, SearchIntent $intent, array $searchTerms): array
    {
        $scored = [];
        foreach ($candidates as $c) {
            $score = 0.0;

            // Search position: earlier results weighted higher
            $score += max(0, (11 - $c->position)) * 0.5;

            // Domain preference by intent
            $score += $this->domainPreferenceScore($c->domain, $intent);

            // Lexical overlap: search terms in title + snippet
            $titleTerms = $this->extractTerms($c->title);
            $snippetTerms = $this->extractTerms($c->snippet);
            $overlap = count(array_intersect($searchTerms, $titleTerms)) * 2.0
                     + count(array_intersect($searchTerms, $snippetTerms)) * 1.0;
            $score += $overlap;

            // Penalize known low-quality or spam domains
            if ($this->isLowQualityDomain($c->domain)) {
                $score -= self::LOW_QUALITY_PENALTY;
            }

            $candidate = clone $c;
            // Attach score as dynamic property
            $candidate->score = $score;
            $scored[] = $candidate;
        }

        usort($scored, fn($a, $b) => $b->score <=> $a->score);

        return $scored;
    }

    /**
     * Optional LLM rerank. Picks the best ordering for the top N candidates.
     * Combines with the deterministic base score — model can reorder within
     * the top tier but cannot promote a low-scored candidate to #1.
     *
     * @param Candidate[] $candidates Already deterministically scored
     * @param string $question
     * @param SearchIntent $intent
     * @param int $rerankTop Only rerank the top N candidates
     * @return Candidate[] reordered candidates
     */
    public function llmRerank(array $candidates, string $question, SearchIntent $intent, int $rerankTop = 8): array
    {
        if (count($candidates) <= 2) {
            return $candidates;
        }

        $toRerank = array_slice($candidates, 0, $rerankTop);
        $rest = array_slice($candidates, $rerankTop);

        $lines = ["Candidates for \"{$question}\":\n"];
        foreach ($toRerank as $c) {
            $lines[] = "[{$c->position}] {$c->title} — " .
                       mb_substr($c->snippet, 0, 100);
        }

        $preference = match ($intent) {
            SearchIntent::ProductSpecs => 'Prefer official manufacturer or specification pages.',
            SearchIntent::SoftwareDocs => 'Prefer official documentation and repository sources.',
            SearchIntent::News => 'Prefer established news sources. Prioritize recency.',
            SearchIntent::Academic => 'Prefer peer-reviewed sources and academic databases.',
            SearchIntent::Recommendation => 'Prefer comparison and review sites.',
            default => 'Prefer authoritative, primary sources.',
        };

        $prompt = implode("\n", $lines) . "\n\n" .
                  "Return candidate numbers in best-first order.\n" .
                  "{$preference}\n" .
                  "Output only comma-separated integers.";

        try {
            $raw = $this->agent->chat([
                ['role' => 'user', 'content' => $prompt],
            ], false, null, 0.1);

            $order = $this->parseRerankOrder($raw, count($toRerank));
            if (count($order) === count($toRerank)) {
                $reordered = [];
                foreach ($order as $pos) {
                    $reordered[] = $toRerank[$pos];
                }
                return array_merge($reordered, $rest);
            }
        } catch (\Throwable $e) {
            error_log("CandidateRanker: LLM rerank failed — {$e->getMessage()}");
        }

        return $candidates;  // Fall back to deterministic ordering
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function domainPreferenceScore(string $domain, SearchIntent $intent): float
    {
        $prefs = self::DOMAIN_PREFERENCES[$intent->value] ?? [];
        foreach ($prefs as $preferred => $boost) {
            if (str_ends_with($domain, $preferred)) {
                return $boost;
            }
        }
        return 0.0;
    }

    private function extractTerms(string $text): array
    {
        $words = preg_split('/\s+/', strtolower($text));
        return array_filter(array_map(fn($w) => trim($w, ".,;:!?\"'()[]"), $words), fn($w) => strlen($w) > 1);
    }

    private function isLowQualityDomain(string $domain): bool
    {
        foreach (self::LOW_QUALITY_DOMAINS as $bad) {
            if (str_ends_with($domain, $bad)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse comma-separated integers from the LLM rerank response.
     *
     * @return array<int> position indices
     */
    private function parseRerankOrder(string $raw, int $maxPos): array
    {
        preg_match_all('/\d+/', $raw, $matches);
        $order = array_map('intval', $matches[0]);
        $order = array_values(array_unique($order));
        return array_filter($order, fn($p) => $p >= 0 && $p < $maxPos);
    }
}
