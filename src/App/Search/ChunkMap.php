<?php

namespace App\Search;

/**
 * The evidence table of contents: one entry per emitted unit — a headline and a short description
 * of what that passage contains.
 *
 * Two properties are the whole point:
 *   - descriptions describe the SOURCE, never the question that fetched it, so the map cannot go
 *     stale when the next question is about a different part of the same source;
 *   - the map is NOT injected into the prompt. It is stored on the evidence row and delivered only
 *     when the model asks to browse it (see SearchSessionEvidenceTool::mode 'map'), and it costs
 *     nothing while unused.
 *
 * Completeness is verified here, deterministically, rather than trusted to the model: every unit
 * that was sent must come back with a non-empty description, and anything missing falls back to the
 * unit's own opening words. The guarantee is "verified complete, approximately neutral".
 *
 * The map is produced by the same single condenser call that produces the facts — see
 * SourceCondenser::enrichBatched().
 */
final class ChunkMap
{
    public const MAP_MARKER = 'MAP:';
    public const FACTS_MARKER = 'FACTS:';

    /** Opening words of a unit, used when the model returned no description for it. */
    private const FALLBACK_WORDS = 14;

    /**
     * Split a condenser answer into its map and facts sections.
     *
     * BOTH markers must be present for the new format. A half-formatted answer is treated as
     * facts-only (the pre-map behaviour), so a map line can never be mistaken for a claim; the map
     * is then built entirely from fallbacks. That keeps the parser deterministic in both directions.
     *
     * @return array{map:string, facts:string, formatted:bool}
     */
    public static function splitSections(string $raw): array
    {
        $lines = explode("\n", $raw);
        $mapAt = null;
        $factsAt = null;

        foreach ($lines as $i => $line) {
            $marker = strtoupper(trim((string)$line));
            if ($mapAt === null && $marker === self::MAP_MARKER) {
                $mapAt = $i;
                continue;
            }
            if ($marker === self::FACTS_MARKER) {
                $factsAt = $i;
                break;
            }
        }

        if ($mapAt === null || $factsAt === null || $factsAt < $mapAt) {
            return ['map' => '', 'facts' => $raw, 'formatted' => false];
        }

        $map = implode("\n", array_slice($lines, $mapAt + 1, $factsAt - $mapAt - 1));
        $facts = implode("\n", array_slice($lines, $factsAt + 1));

        return ['map' => $map, 'facts' => $facts, 'formatted' => true];
    }

    /**
     * Parse one `[S#-C#] Headline | description` line per unit.
     *
     * An id that was not sent is dropped — a hallucinated id must never become an addressable entry
     * — and a line with no pipe keeps its whole text as the headline, leaving completion to fill the
     * description. Later duplicates of an id do not overwrite the first.
     *
     * @param WebChunk[] $chunks units that were sent, keyed by chunkId
     * @return array<string, array{headline:string, description:string}>
     */
    public static function parseSection(string $section, array $chunks): array
    {
        $byId = [];
        foreach ($chunks as $chunk) {
            $byId[$chunk->chunkId] = true;
        }

        $parsed = [];
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^-?\s*\[([^\]]+)\]\s*(.+)$/', $line, $m)) {
                continue;
            }

            $id = trim($m[1]);
            if (!isset($byId[$id]) || isset($parsed[$id])) {
                continue;
            }

            $body = trim($m[2]);
            if ($body === '') {
                continue;
            }

            $parts = explode('|', $body, 2);
            $headline = trim($parts[0]);
            $description = isset($parts[1]) ? trim($parts[1]) : '';

            if ($headline === '' && $description !== '') {
                $headline = $description;
                $description = '';
            }
            if ($headline === '') {
                continue;
            }

            $parsed[$id] = ['headline' => $headline, 'description' => $description];
        }

        return $parsed;
    }

    /**
     * One entry per unit, in source order — the model's description where it supplied one, the unit's
     * own opening words where it did not. Every unit that was sent appears exactly once, which is what
     * "verified complete" means here.
     *
     * @param array<string, array{headline:string, description:string}> $parsed
     * @param WebChunk[] $chunks
     * @return array<int, array{chunkId:string, headline:string, description:string, chars:int, described:bool}>
     */
    public static function build(array $parsed, array $chunks): array
    {
        $entries = [];
        foreach ($chunks as $chunk) {
            $id = $chunk->chunkId;
            $described = isset($parsed[$id]);
            $headline = $described ? $parsed[$id]['headline'] : self::openingWords($chunk->text);
            $description = $described ? $parsed[$id]['description'] : '';

            if ($headline === '') {
                $headline = $id;
            }

            $entries[] = [
                'chunkId' => $id,
                'headline' => $headline,
                'description' => $description,
                'chars' => strlen($chunk->text),
                'described' => $described,
            ];
        }

        return $entries;
    }

    /** The unit's own first words: the plain fallback for a description the model did not return. */
    public static function openingWords(string $text): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($flat === '') {
            return '';
        }

        $words = explode(' ', $flat);
        $head = implode(' ', array_slice($words, 0, self::FALLBACK_WORDS));

        return count($words) > self::FALLBACK_WORDS ? $head . '…' : $head;
    }

    /**
     * Persist form: the entries plus the map's own status, so a reader can tell a map that covers
     * every unit from one that is still being built (status 'partial') or was never built (no row).
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public static function encode(array $entries, string $status = 'complete'): string
    {
        return (string)json_encode(
            ['status' => $status, 'entries' => array_values($entries)],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array{status:string, entries:array<int, array<string, mixed>>}
     */
    public static function decode(?string $json): array
    {
        $empty = ['status' => 'none', 'entries' => []];
        if ($json === null || $json === '' || $json === 'null') {
            return $empty;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
            return $empty;
        }

        $entries = [];
        foreach ($decoded['entries'] as $entry) {
            if (!is_array($entry) || empty($entry['chunkId'])) {
                continue;
            }
            $entries[] = [
                'chunkId' => (string)$entry['chunkId'],
                'headline' => (string)($entry['headline'] ?? ''),
                'description' => (string)($entry['description'] ?? ''),
                'chars' => (int)($entry['chars'] ?? 0),
                'described' => (bool)($entry['described'] ?? false),
            ];
        }

        return [
            'status' => (string)($decoded['status'] ?? 'complete'),
            'entries' => $entries,
        ];
    }

    /**
     * Model-facing rendering of one source's map: id, headline, description and the size a read of
     * that unit would cost, so the model can budget its own request.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public static function renderEntries(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $description = trim((string)($entry['description'] ?? ''));
            $line = '[' . $entry['chunkId'] . '] ' . trim((string)($entry['headline'] ?? ''));
            if ($description !== '') {
                $line .= ' — ' . $description;
            }
            $line .= ' (' . self::sizeLabel((int)($entry['chars'] ?? 0)) . ')';
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** Human/model-readable size of a passage: characters are what the read actually costs. */
    public static function sizeLabel(int $chars): string
    {
        return $chars >= 1000 ? round($chars / 1000, 1) . 'k chars' : $chars . ' chars';
    }

    /**
     * Keep the units that fit a read budget, in the order they were requested, and name what was
     * withheld. A single unit that alone exceeds the budget is still returned: an empty read would
     * be worse than an oversized one, and the caller can say so in the note.
     *
     * @param string[] $ids requested unit ids, in order
     * @param array<string, WebChunk> $byId available units
     * @return array{kept:array<int, string>, omitted:array<int, string>, missing:array<int, string>, chars:int}
     */
    public static function fitToBudget(array $ids, array $byId, int $maxChars): array
    {
        $kept = [];
        $omitted = [];
        $missing = [];
        $chars = 0;
        $exhausted = false;

        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                $missing[] = $id;
                continue;
            }

            $size = strlen($byId[$id]->text);
            if ($exhausted || ($chars > 0 && $chars + $size > $maxChars)) {
                $exhausted = true;
                $omitted[] = $id;
                continue;
            }

            $kept[] = $id;
            $chars += $size;
        }

        return ['kept' => $kept, 'omitted' => $omitted, 'missing' => $missing, 'chars' => $chars];
    }
}
