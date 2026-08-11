<?php

namespace App\Enums;

enum SearchIntent: string
{
    case General = 'general';
    case ProductSpecs = 'product_specs';
    case SoftwareDocs = 'software_docs';
    case News = 'news';
    case Recommendation = 'recommendation';
    case Academic = 'academic';

    /**
     * Deterministic intent classification from query text.
     * First match wins — ordering matters (Software before ProductSpecs etc.)
     */
    public static function classify(string $query): self
    {
        $lower = strtolower($query);

        $patterns = [
            self::SoftwareDocs->value => [
                '/\b(documentation|docs?|api|endpoint|function|method|class|parameter|config|install|setup|usage)\b/',
                '/\b(how (to|do I)|example|tutorial)\b.*\b(code|function|api|library|package)\b/',
            ],
            self::ProductSpecs->value => [
                '/\b(specs?|specifications?|datasheet|technical details?|dimensions?|weight|release date)\b/',
                '/\b(how much|how many|what are the|compare)\b.*\b(specs?|features?|specifications?)\b/',
            ],
            self::News->value => [
                '/\b(today|yesterday|this week|latest|breaking|just (announced|released|launched)|recent)\b/',
                '/\b(news|update|announcement|statement|press release)\b/',
            ],
            self::Academic->value => [
                '/\b(study|research|paper|journal|doi|arxiv|pubmed|citation|peer.reviewed|methodology)\b/',
            ],
            self::Recommendation->value => [
                '/\b(best|top|recommend|review|vs|versus|compared?|alternative|which (one|is better))\b/',
            ],
        ];

        foreach ($patterns as $intent => $regexes) {
            foreach ($regexes as $regex) {
                if (preg_match($regex, $lower)) {
                    return self::from($intent);
                }
            }
        }

        return self::General;
    }
}
