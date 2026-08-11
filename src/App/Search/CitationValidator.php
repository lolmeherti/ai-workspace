<?php

namespace App\Search;

final class CitationValidator
{
    /**
     * Ensures answer only cites valid retrieved source IDs.
     * Strips hallucinated IDs ([S4] when only S1, S2 exist).
     *
     * Does NOT detect missing citations, valid-but-incorrect citations,
     * unsupported claims, or [S1] attached to an S2 fact — those remain
     * prompt-enforced at the model level.
     *
     * @param string $answer The model's final answer text
     * @param array<string> $validSourceIds e.g. ['S1', 'S2']
     */
    public function sanitizeCitations(string $answer, array $validSourceIds): string
    {
        if (empty($validSourceIds)) {
            return $answer;
        }

        $validSet = array_flip($validSourceIds);

        return preg_replace_callback('/\[(S\d+)\]/', function ($matches) use ($validSet) {
            $sourceId = $matches[1];
            return isset($validSet[$sourceId]) ? "[{$sourceId}]" : '';
        }, $answer);
    }

    /**
     * Returns the set of source IDs referenced in an answer.
     *
     * @return array<string>
     */
    public function extractCitations(string $answer): array
    {
        preg_match_all('/\[(S\d+)\]/', $answer, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Checks whether any citation in the answer references an invalid source.
     */
    public function hasHallucinatedCitations(string $answer, array $validSourceIds): bool
    {
        $cited = $this->extractCitations($answer);
        $validSet = array_flip($validSourceIds);

        foreach ($cited as $id) {
            if (!isset($validSet[$id])) {
                return true;
            }
        }

        return false;
    }
}
