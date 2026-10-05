<?php

namespace App\Search;

final class CitationValidator
{
    /**
     * Clean an answer before it is stored.
     *
     * The citation tokens (#REFID_n) the model was told to write are KEPT — the UI turns each one into a
     * source badge, which is why they are no longer stripped here. What is dropped is what could not render:
     * a token whose number was never shown this turn (a dead badge), and any older citation shape the model
     * falls back to ([S1], "S2/S3", "(S2)") for which no badge exists.
     *
     * Built from the actual valid IDs, so nothing else can be mis-stripped and no hallucinated ID survives.
     *
     * @param string $answer The model's final answer text
     * @param array<string> $validSourceIds e.g. ['S1', 'S2'] (or already '#REFID_1')
     */
    public function sanitizeCitations(string $answer, array $validSourceIds): string
    {
        $validNumbers = [];
        foreach ($validSourceIds as $id) {
            if (preg_match('/^(?:#REFID_|S)(\d+)$/', (string) $id, $m)) {
                $validNumbers[(int) $m[1]] = true;
            }
        }

        // Source badges: the marker stays, but only for ids this turn actually showed. "007" is normalised so
        // the UI has one shape to look up.
        $answer = preg_replace_callback(
            '/#REFID_(\d+)(?:-C\d+)?/',
            static fn(array $m): string => isset($validNumbers[(int) $m[1]]) ? '#REFID_' . (int) $m[1] : '',
            $answer
        ) ?? $answer;

        if (!empty($validSourceIds)) {
            $sorted = $validSourceIds;
            usort($sorted, static fn($a, $b) => strlen($b) <=> strlen($a));
            $ids = implode('|', array_map(static fn($id) => preg_quote($id, '/'), $sorted));

            // Deterministic: strip exactly the retrieved IDs — [S1], [S1-C4], [S1-C1, S3-C2].
            $pattern = '/\[(?:' . $ids . ')(?:-C\d+)?(?:\s*,\s*(?:' . $ids . ')(?:-C\d+)?)*\]/';
            $answer = preg_replace($pattern, '', $answer);

            // Everything the model can put between two ids when it cites in prose rather than in brackets.
            $sep = '\s*(?:\/|,|&|\+|and|or|to)\s*';

            // Bracket-less citations inside parentheses, single or a run: "(S2)", "(S1, S2)". The parens go
            // with them, otherwise the answer keeps an empty "()" where the citation was.
            $inParens = '/\(\s*(?:' . $ids . ')(?:' . $sep . '(?:' . $ids . '))*\s*\)/';
            $answer = preg_replace($inParens, '', $answer);

            // A run of two or more retrieved IDs, however it is punctuated: "S2/S3 are eBay US listings",
            // "see S1, S2 and S3". A LONE id is deliberately left alone — these ids are one letter and a
            // digit and collide with real names ("AWS S3", "Galaxy S2"), so mangling a genuine mention is the
            // worse failure. A run of them is a citation and nothing else.
            $run = '/\b(?:' . $ids . ')\b(?:' . $sep . '\b(?:' . $ids . ')\b)+/';
            $answer = preg_replace($run, '', $answer);
        }

        // Fallback: strip any residual citation-shaped token (hallucinated or stale IDs).
        $answer = preg_replace('/\[S\d+(?:-C\d+)?(?:\s*,\s*S\d+(?:-C\d+)?)*\]/', '', $answer);

        // A chunk reference is a citation in every context, so it needs no ID list to be recognised.
        $answer = preg_replace('/\bS\d+-C\d+\b/', '', $answer);

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
