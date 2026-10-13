<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Logger;
use App\Repositories\ChatSessionRepository;
use App\Search\ChunkMap;
use App\Search\WebChunk;
use App\Services\Tools\SearchSessionEvidenceTool;

/**
 * The evidence table of contents and reading by unit id.
 *
 * What these pin: the map is complete and question-free by construction (every unit sent comes back,
 * with the unit's own opening words where the model described nothing, and a hallucinated id can
 * never become an addressable entry); the map split is deterministic in both directions, so a
 * half-formatted condenser answer neither loses its map nor has map lines parsed as claims; a read
 * returns stored text in the order asked for, names what it withheld, and never invents an id.
 *
 * No model call is made anywhere here — the map is built from text, and the tool modes are storage
 * reads.
 */
class ChunkMapTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(private Database $db)
    {
        Logger::setDatabase($db);
        Logger::clearModelName();
    }

    public function run(): bool
    {
        echo "\n=== Evidence map and unit reads ===\n";

        $this->testSections();
        $this->testParsing();
        $this->testCompletion();
        $this->testBudget();
        $this->testToolModes();

        echo "\n--- ChunkMap: {$this->passed} passed, {$this->failed} failed ---\n";
        foreach ($this->failures as $f) echo "  FAIL: {$f}\n";
        return $this->failed === 0;
    }

    // ── the section split ────────────────────────────────────────────────

    private function testSections(): void
    {
        $raw = "MAP:\n- [S1-C1] Intro | what the opening says.\nFACTS:\n- [S1-C1] A fact.";
        $s = ChunkMap::splitSections($raw);
        $this->test('both markers: the answer is treated as formatted', $s['formatted'] === true);
        $this->testEq('the map section holds only the map lines', "- [S1-C1] Intro | what the opening says.", trim($s['map']));
        $this->testEq('the facts section holds only the claim lines', '- [S1-C1] A fact.', trim($s['facts']));

        $legacy = "- [S1-C1] A plain claim from before the map existed.";
        $s = ChunkMap::splitSections($legacy);
        $this->test('no markers: parsed as claims-only', $s['formatted'] === false);
        $this->testEq('and the whole answer is the facts section', $legacy, $s['facts']);
        $this->testEq('with no map section', '', $s['map']);

        $half = "FACTS:\n- [S1-C1] Only facts, the map section never arrived.";
        $this->test('facts without a map marker is not formatted', ChunkMap::splitSections($half)['formatted'] === false);

        $swapped = "FACTS:\n- [S1-C1] claim\nMAP:\n- [S1-C1] H | d";
        $s = ChunkMap::splitSections($swapped);
        $this->test('markers in the wrong order is not formatted', $s['formatted'] === false);
        $this->test('and the text stays in the facts section, so no map line becomes a claim', str_contains($s['facts'], 'MAP:'));
    }

    // ── map line parsing ─────────────────────────────────────────────────

    private function testParsing(): void
    {
        $chunks = [
            'S1-C1' => $this->chunk('S1-C1', 'First passage about the forest and the walk.'),
            'S1-C2' => $this->chunk('S1-C2', 'Second passage about the city.'),
        ];

        $parsed = ChunkMap::parseSection(
            "- [S1-C1] The forest | describes the walk and the trees.\n"
            . "- [S1-C2] The city | describes arriving in town.",
            $chunks
        );
        $this->testEq('a headline and description are split on the pipe', 'The forest', $parsed['S1-C1']['headline']);
        $this->testEq('the description is kept verbatim', 'describes arriving in town.', $parsed['S1-C2']['description']);

        $parsed = ChunkMap::parseSection("- [S9-C9] Invented | not in the source.\n- [S1-C1] Real | yes.", $chunks);
        $this->test('an id that was not sent is dropped', !isset($parsed['S9-C9']));
        $this->testEq('while the real one survives', 'Real', $parsed['S1-C1']['headline']);

        $parsed = ChunkMap::parseSection("- [S1-C1] First | kept.\n- [S1-C1] Second | overwrites nothing.", $chunks);
        $this->testEq('a repeated id does not overwrite the first line', 'First', $parsed['S1-C1']['headline']);

        $parsed = ChunkMap::parseSection('- [S1-C1] A headline with no pipe', $chunks);
        $this->testEq('a line without a pipe is all headline', 'A headline with no pipe', $parsed['S1-C1']['headline']);
        $this->testEq('with an empty description for completion to leave alone',
            '', $parsed['S1-C1']['description']);

        $parsed = ChunkMap::parseSection("MAP:\n- [S1-C1] x", $chunks);
        $this->test('a stray marker line is not an entry', !isset($parsed['MAP']));
    }

    // ── completeness and the fallback ────────────────────────────────────

    private function testCompletion(): void
    {
        $chunks = [
            'S1-C1' => $this->chunk('S1-C1', 'Alpha passage that talks about the beginning of things.'),
            'S1-C2' => $this->chunk('S1-C2', 'Beta passage covering the middle, with a table of figures.'),
            'S1-C3' => $this->chunk('S1-C3', 'Gamma passage, the closing part.'),
        ];

        $entries = ChunkMap::build(['S1-C1' => ['headline' => 'Alpha', 'description' => 'the start']], $chunks);
        $this->testEq('every unit that was sent appears', 3, count($entries));
        $this->testEq('in source order', ['S1-C1', 'S1-C2', 'S1-C3'], array_column($entries, 'chunkId'));
        $this->test('a described unit keeps the model text', $entries[0]['described'] === true);
        $this->testEq('with its headline', 'Alpha', $entries[0]['headline']);
        $this->test('an undescribed unit is still complete', $entries[1]['described'] === false);
        $this->test('and falls back to its own opening words',
            str_starts_with($entries[1]['headline'], 'Beta passage covering'));
        $this->testEq('with no invented description', '', $entries[1]['description']);
        $this->testEq('and carries the size a read would cost', strlen($chunks['S1-C2']->text), $entries[1]['chars']);

        $this->testEq('opening words are capped', 14, count(explode(' ', ChunkMap::openingWords(implode(' ', range(1, 40))))));
        $this->test('a capped headline is marked as cut', str_ends_with(ChunkMap::openingWords(implode(' ', range(1, 40))), '…'));
        $this->testEq('whitespace is flattened', 'one line there', ChunkMap::openingWords("one\n  line\tthere"));
        $this->testEq('an empty passage yields no headline', '', ChunkMap::openingWords('   '));

        $encoded = ChunkMap::encode($entries);
        $decoded = ChunkMap::decode($encoded);
        $this->testEq('a map round-trips', 3, count($decoded['entries']));
        $this->testEq('with its status', 'complete', $decoded['status']);
        $this->testEq('a partial map says so', 'partial', ChunkMap::decode(ChunkMap::encode($entries, 'partial'))['status']);
        $this->testEq('a missing map decodes as none', 'none', ChunkMap::decode(null)['status']);
        $this->testEq('junk decodes as none with no entries', [], ChunkMap::decode('{not json')['entries']);
    }

    // ── the read budget ──────────────────────────────────────────────────

    private function testBudget(): void
    {
        $byId = [
            'S1-C1' => $this->chunk('S1-C1', str_repeat('a', 100)),
            'S1-C2' => $this->chunk('S1-C2', str_repeat('b', 100)),
            'S1-C3' => $this->chunk('S1-C3', str_repeat('c', 100)),
        ];

        $fit = ChunkMap::fitToBudget(['S1-C1', 'S1-C2', 'S1-C3'], $byId, 250);
        $this->testEq('units that fit are kept, in the order asked for', ['S1-C1', 'S1-C2'], $fit['kept']);
        $this->testEq('and the rest are named as withheld', ['S1-C3'], $fit['omitted']);
        $this->testEq('with the characters actually returned', 200, $fit['chars']);

        $fit = ChunkMap::fitToBudget(['S1-C1', 'S7-C9'], $byId, 250);
        $this->testEq('an id that does not exist is named separately', ['S7-C9'], $fit['missing']);
        $this->testEq('and is not kept', ['S1-C1'], $fit['kept']);

        $fit = ChunkMap::fitToBudget(['S1-C1', 'S1-C2'], $byId, 50);
        $this->testEq('one unit over the whole budget is still returned rather than nothing', ['S1-C1'], $fit['kept']);

        $this->testEq('half the window is the read ceiling', 131072 * 4,
            SearchSessionEvidenceTool::readBudgetChars(262144));
        $this->testEq('an unknown window still allows a small read', 4000,
            SearchSessionEvidenceTool::readBudgetChars(0));
        $this->testEq('a small window scales down with it', 32768,
            SearchSessionEvidenceTool::readBudgetChars(16384));
    }

    // ── the tool's modes over real rows ──────────────────────────────────

    private function testToolModes(): void
    {
        $sessionId = 999000001;
        (new ChatSessionRepository($this->db))->ensureExists($sessionId);

        $mapped = [
            $this->chunk('S1-C1', 'The forest passage, describing the walk in.'),
            $this->chunk('S1-C2', 'The city passage, describing what happened after.'),
        ];
        $unmapped = [$this->chunk('S2-C1', 'A second source nobody has mapped yet.')];

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => 'evidence row',
            'message_type' => 'data_fetching',
            'tool_name' => 'search_web',
            'search_query' => 'forest city',
            'source_map' => json_encode(['S1' => ['title' => 'Forest and City', 'domain' => 'example.test']]),
            'backing_chunks' => json_encode(array_map(fn($c) => $this->chunkArray($c), $mapped), JSON_UNESCAPED_UNICODE),
            'chunk_map' => ChunkMap::encode([
                ['chunkId' => 'S1-C1', 'headline' => 'The forest', 'description' => 'the walk in', 'chars' => 41, 'described' => true],
                ['chunkId' => 'S1-C2', 'headline' => 'The city', 'description' => 'what came after', 'chars' => 47, 'described' => true],
            ]),
            'raw_evicted' => 1,
            'atomic_context' => json_encode([['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'A fact.']]),
            'token_estimate' => 20,
        ]);
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'system',
            'message' => 'second evidence row',
            'message_type' => 'data_fetching',
            'tool_name' => 'search_web',
            'search_query' => 'other',
            'source_map' => json_encode(['S2' => ['title' => 'Other Source', 'domain' => 'other.test']]),
            'backing_chunks' => json_encode(array_map(fn($c) => $this->chunkArray($c), $unmapped), JSON_UNESCAPED_UNICODE),
            'raw_evicted' => 0,
            'token_estimate' => 10,
        ]);

        $tool = new SearchSessionEvidenceTool($this->db);
        $emit = static function (): void {};

        $map = $tool->execute([], $sessionId, [], $emit, '{}');
        $this->test('the map lists the source with its title', str_contains($map, 'Forest and City'));
        $this->test('and names which tool acquired it', str_contains($map, 'acquired by search_web'));
        $this->test('with one entry per unit', str_contains($map, '[S1-C1] The forest — the walk in'));
        $this->test('including the description of the later part', str_contains($map, '[S1-C2] The city'));
        $this->test('an undescribed source says so rather than being hidden', str_contains($map, 'no map yet'));
        $this->test('but its unit ids are still listed, so they can be read', str_contains($map, '[S2-C1]'));
        $this->test('with the size a read would cost',
            str_contains($map, '[S2-C1] ' . strlen('A second source nobody has mapped yet.') . ' chars'));
        $this->test('and its heading path, which costs nothing to state',
            str_contains($map, 'Section'));
        $this->test('and the id is named with its title', str_contains($map, 'S2 — Other Source'));
        $this->test('the map teaches how to read a passage from it',
            str_contains($map, 'call this tool again with ids'));
        $this->testEq('a map read reports no retrieved sources (nothing was retrieved)',
            [], $tool->getLastRetrievedSourceIds());

        $read = $tool->execute(['ids' => ['S1-C2', 'S1-C1']], $sessionId, [], $emit, '{}');
        $this->test('a read returns the stored text', str_contains($read, 'The city passage'));
        $this->test('in the order asked for', strpos($read, 'The city passage') < strpos($read, 'The forest passage'));
        $this->testEq('and reports the sources it pulled from', ['S1'], $tool->getLastRetrievedSourceIds());

        $read = $tool->execute(['ids' => ['S1-C1']], $sessionId, [], $emit, '{}');
        $this->test('reading one unit does not drag the whole source along',
            !str_contains($read, 'The city passage'));

        $read = $tool->execute(['ids' => ['S9-C9']], $sessionId, [], $emit, '{}');
        $this->test('an id that does not exist is refused, not invented', str_contains($read, 'none of the requested unit ids exist'));

        $read = $tool->execute(['ids' => '[S1-C1]'], $sessionId, [], $emit, '{}');
        $this->test('a bracketed citation is accepted as an id', str_contains($read, 'The forest passage'));

        // The form a reference-tier model reached for first, unasked: the source id the evidence block shows it.
        // Before this worked, "ids": ["S1"] fell through to the phrase path and came back "no stored passage
        // matches S1" — measured on three of three runs.
        $read = $tool->execute(['ids' => ['S1']], $sessionId, [], $emit, '{}');
        $this->test('a bare source id reads that source', str_contains($read, 'The forest passage') && str_contains($read, 'The city passage'));
        $this->test('and names its units in order', strpos($read, 'The forest passage') < strpos($read, 'The city passage'));
        $this->testEq('reports that source as retrieved', ['S1'], $tool->getLastRetrievedSourceIds());

        $read = $tool->execute(['ids' => ['S7']], $sessionId, [], $emit, '{}');
        $this->test('an unknown source id is refused, not invented', str_contains($read, 'none of the requested unit ids exist'));

        $search = $tool->execute(['query' => 'forest'], $sessionId, [], $emit, '{}');
        $this->test('phrase search still works unchanged', str_contains($search, 'SESSION EVIDENCE'));

        // The point of the design: a search's own result carries the map, so a passage whose words the query
        // never used can still be found — chosen by what it covers, at the moment the search proved
        // insufficient. Without this the model has no way to discover that the passage exists.
        $this->test('a search result carries the map with it', str_contains($search, 'everything else stored in this conversation'));
        $this->test('and says to choose by coverage, not by repeated words',
            str_contains($search, 'not by whether it repeats the question'));
        $this->test('naming the passages it could read instead',
            str_contains($search, 'To read any of them in full') && str_contains($search, '[S1-C1]'));
        $this->test('the map carries the descriptions, so the choice is by meaning',
            str_contains($search, 'The forest') && str_contains($search, 'the walk in'));

        // A bare call is what the tool's own description invites ("re-read the raw data of previous
        // sources"), and it must answer with the ledger rather than an error or a phrase search.
        $bare = $tool->execute([], $sessionId, [], $emit, '{}');
        $this->test('a call with no arguments answers with the map', str_contains($bare, 'SESSION EVIDENCE MAP'));
        $this->test('and shows the passages it could be read from', str_contains($bare, '[S1-C1]'));
        $this->testEq('a bare map call retrieves nothing (nothing was retrieved)',
            [], $tool->getLastRetrievedSourceIds());

        $byIds = $tool->execute(['ids' => ['S1-C2']], $sessionId, [], $emit, '{}');
        $this->test('ids without a mode read those passages', str_contains($byIds, 'The city passage'));
        $empty = $tool->execute([], 999000002, [], $emit, '{}');
        $this->test('a session with no evidence says so', str_contains($empty, 'no evidence has been stored'));
        (new ChatSessionRepository($this->db))->ensureExists(999000002);

        $this->db->query('DELETE FROM chat_history WHERE session_id = ?', [$sessionId]);
        $this->db->query('DELETE FROM chat_sessions WHERE id IN (?, ?)', [$sessionId, 999000002]);
        $left = (int)($this->db->query('SELECT COUNT(*) c FROM chat_history WHERE session_id = ?', [$sessionId])[0]['c'] ?? 0);
        $this->testEq('probe rows are cleaned up after the run', 0, $left);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function chunk(string $chunkId, string $text): WebChunk
    {
        $sourceId = substr($chunkId, 0, (int)strpos($chunkId, '-C'));

        return new WebChunk(
            sourceId: $sourceId,
            chunkId: $chunkId,
            url: 'https://example.test/page',
            finalUrl: 'https://example.test/page',
            title: 'Title ' . $sourceId,
            domain: 'example.test',
            publishedAt: null,
            updatedAt: null,
            fetchedAt: '2026-10-10T00:00:00Z',
            headingPath: ['Section'],
            sectionType: 'paragraph',
            text: $text,
            position: (int)substr($chunkId, (int)strpos($chunkId, '-C') + 2),
        );
    }

    private function chunkArray(WebChunk $chunk): array
    {
        return [
            'sourceId' => $chunk->sourceId,
            'chunkId' => $chunk->chunkId,
            'url' => $chunk->url,
            'finalUrl' => $chunk->finalUrl,
            'title' => $chunk->title,
            'domain' => $chunk->domain,
            'publishedAt' => $chunk->publishedAt,
            'updatedAt' => $chunk->updatedAt,
            'fetchedAt' => $chunk->fetchedAt,
            'headingPath' => $chunk->headingPath,
            'sectionType' => $chunk->sectionType,
            'text' => $chunk->text,
            'position' => $chunk->position,
        ];
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $this->failures[] = "{$label}: expected {$e}, got {$a}";
        echo "  [FAIL] {$label} — expected {$e}, got {$a}\n";
    }
}
