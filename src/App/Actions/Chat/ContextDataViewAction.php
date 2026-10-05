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
            "SELECT message, source_map, tool_name, search_query, raw_evicted, atomic_context, atomic_tokens, token_estimate
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
            'parsed' => self::parseSources($row['message'] ?? ''),
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
                    $chunks[] = self::unescape($chunk[1]);
                }
            }

            $claims = [];
            if (preg_match_all('/<claim\b[^>]*>(.*?)<\/claim>/s', $inner, $cl, PREG_SET_ORDER)) {
                foreach ($cl as $claim) {
                    $claims[] = self::unescape($claim[1]);
                }
            }

            if ($title !== '' || $domain !== '' || $chunks !== [] || $claims !== []) {
                $sources[] = ['id' => $s[1], 'title' => $title, 'domain' => $domain, 'chunks' => $chunks, 'claims' => $claims];
            }
        }
        return $sources;
    }

    private static function unescape(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
