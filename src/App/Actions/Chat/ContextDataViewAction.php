<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;

class ContextDataViewAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $historyId = (int)($_GET['view_context'] ?? 0);
        if ($historyId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing context item id.'], 400);
            return;
        }

        $rows = $this->db->query(
            "SELECT message, source_map, tool_name, search_query, raw_evicted, atomic_context, atomic_tokens,
                    token_estimate, selected_chunks, backing_chunks
             FROM chat_history
             WHERE id = :id AND message_type = 'data_fetching'",
            [':id' => $historyId]
        );

        if (empty($rows)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Context item not found.'], 404);
            return;
        }

        $row = $rows[0];
        $sources = null;
        if (!empty($row['source_map'])) {
            $decoded = json_decode($row['source_map'], true);
            if (is_array($decoded)) {
                $sources = $decoded;
            }
        }

        $atoms = null;
        if (!empty($row['atomic_context'])) {
            $decoded = json_decode($row['atomic_context'], true);
            if (is_array($decoded)) {
                $atoms = $decoded;
            }
        }

        $this->jsonResponse([
            'status' => 'success',
            'id' => $historyId,
            'message' => $row['message'] ?? '',
            'sources' => $sources,
            'tool_name' => $row['tool_name'] ?? '',
            'search_query' => $row['search_query'] ?? '',
            'raw_evicted' => (bool)($row['raw_evicted'] ?? 0),
            'atomic_context' => $atoms,
            'atomic_tokens' => (int)($row['atomic_tokens'] ?? 0),
            'token_estimate' => (int)($row['token_estimate'] ?? 0),
            'parsed' => self::storedSources($row),
        ]);
    }

    /**
     * Parse the persisted evidence block (EvidenceBuilder <source>/<chunk> XML)
     * into structured source entries for readable rendering.
     *
     * Condenser-format sources (level 3 fitting) carry <claim> blocks and no <chunk>, so both are collected:
     * counting only chunks made five real sources report "~0 tok" in the Context Data view while the model
     * had actually read ~1.5k tokens of their claims (measured on session 1000984, 2026-10-03).
     *
     * @return array<int, array{id: string, title: string, domain: string, chunks: string[], claims: string[]}>
     */
    public static function parseSources(string $message): array
    {
        $sources = [];
        if (!preg_match_all('/<source id="([^"]+)">(.*?)<\/source>/s', $message, $src, PREG_SET_ORDER)) {
            return $sources;
        }
        foreach ($src as $s) {
            $inner = $s[2];

            $title = '';
            if (preg_match('/<title>\s*(.*?)\s*<\/title>/s', $inner, $t)) {
                $title = self::unescape($t[1]);
            }

            $domain = '';
            if (preg_match('/<domain>\s*(.*?)\s*<\/domain>/s', $inner, $d)) {
                $domain = self::unescape($d[1]);
            }

            $chunks = [];
            if (preg_match_all('/<chunk id="[^"]*"[^>]*>(.*?)<\/chunk>/s', $inner, $c, PREG_SET_ORDER)) {
                foreach ($c as $chunk) {
                    $chunks[] = trim(self::unescape($chunk[1]));
                }
            }

            $claims = [];
            if (preg_match_all('/<claim\b[^>]*>(.*?)<\/claim>/s', $inner, $cl, PREG_SET_ORDER)) {
                foreach ($cl as $claim) {
                    $claims[] = trim(self::unescape($claim[1]));
                }
            }

            if ($title !== '' || $domain !== '' || $chunks !== [] || $claims !== []) {
                $sources[] = ['id' => $s[1], 'title' => $title, 'domain' => $domain, 'chunks' => $chunks, 'claims' => $claims];
            }
        }
        return $sources;
    }

    /**
     * The row's sources, each carrying the text that is actually stored for it.
     *
     * @param array<string,mixed> $row
     * @return array<int, array{id: string, title: string, domain: string, chunks: string[], claims: string[]}>
     */
    public static function storedSources(array $row): array
    {
        return self::withStoredText(
            self::parseSources((string) ($row['message'] ?? '')),
            (string) ($row['selected_chunks'] ?? ''),
            (string) ($row['backing_chunks'] ?? '')
        );
    }

    /**
     * Give every source the text it was stored with, when the block it was sent as carries none.
     *
     * A level-3 fit does not shrink a source, it replaces it: the source is condensed into a per-source
     * claim ledger, so the persisted block carries <claim> items and no <chunk> text at all. Reading the
     * block alone therefore presents an AI-written digest as the source itself — measured on session
     * 1002120, a 41,990-character transcript reached this view as 37 claims of ~5,700 characters, and the
     * words that were actually spoken were nowhere in it.
     *
     * The exact chunks are on the same row, so a source whose block carries no text gets its own words back
     * from there. The claims are left exactly where they are: they are the fitted form the prompt carries,
     * not the source, and the two are shown as what they are.
     *
     * @param array<int, array{id: string, title: string, domain: string, chunks: string[], claims: string[]}> $sources
     * @return array<int, array{id: string, title: string, domain: string, chunks: string[], claims: string[]}>
     */
    public static function withStoredText(array $sources, string $selectedChunks, string $backingChunks): array
    {
        $stored = self::storedTextBySource($selectedChunks);
        if ($stored === []) {
            $stored = self::storedTextBySource($backingChunks);
        }
        if ($stored === []) {
            return $sources;
        }

        foreach ($sources as $index => $source) {
            if ($source['chunks'] !== []) {
                continue;
            }
            $text = $stored[(string) $source['id']] ?? [];
            if ($text !== []) {
                $sources[$index]['chunks'] = $text;
            }
        }

        return $sources;
    }

    /**
     * The stored chunk texts, per source id, in document order.
     *
     * @return array<string, array<int, string>>
     */
    private static function storedTextBySource(string $chunksJson): array
    {
        $bySource = [];
        foreach (\App\ChatManager::decodeChunks($chunksJson) as $chunk) {
            if ($chunk->text === '') {
                continue;
            }
            $bySource[$chunk->sourceId][] = $chunk->text;
        }

        return $bySource;
    }

    private static function unescape(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
