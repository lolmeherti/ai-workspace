<?php

namespace App\Search;

final class CitationValidator
{
    /**
     * Ensures answer only cites valid retrieved source IDs.
     * Strips exact-known citations only — [S1], [S1-C4], [S1-C1, S3-C2], [S1, S2].
     * Built from the actual valid IDs, so nothing else can be mis-stripped and no
     * hallucinated ID survives.
     *
     * @param string $answer The model's final answer text
     * @param array<string> $validSourceIds e.g. ['S1', 'S2']
     */
    public function sanitizeCitations(string $answer, array $validSourceIds): string
    {
        if (!empty($validSourceIds)) {
            $sorted = $validSourceIds;
            usort($sorted, static fn($a, $b) => strlen($b) <=> strlen($a));
            $ids = implode('|', array_map(static fn($id) => preg_quote($id, '/'), $sorted));

            // Deterministic: strip exactly the retrieved IDs — [S1], [S1-C4], [S1-C1, S3-C2].
            $pattern = '/\[(?:' . $ids . ')(?:-C\d+)?(?:\s*,\s*(?:' . $ids . ')(?:-C\d+)?)*\]/';
            $answer = preg_replace($pattern, '', $answer);
        }

        // Fallback: strip any residual citation-shaped token (hallucinated or stale IDs).
        $answer = preg_replace('/\[S\d+(?:-C\d+)?(?:\s*,\s*S\d+(?:-C\d+)?)*\]/', '', $answer);

        return $answer;
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
