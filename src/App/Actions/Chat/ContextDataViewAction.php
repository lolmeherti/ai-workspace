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
            "SELECT message, source_map, tool_name, search_query, active_context, token_estimate
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

        $this->jsonResponse([
            'status' => 'success',
            'id' => $historyId,
            'message' => $row['message'] ?? '',
            'sources' => $sources,
            'tool_name' => $row['tool_name'] ?? '',
            'search_query' => $row['search_query'] ?? '',
            'active_context' => (bool)($row['active_context'] ?? 1),
            'token_estimate' => (int)($row['token_estimate'] ?? 0),
            'parsed' => self::parseSources($row['message'] ?? ''),
        ]);
    }

    /**
     * Parse the persisted evidence block (EvidenceBuilder <source>/<chunk> XML)
     * into structured source entries for readable rendering.
     *
     * @return array<int, array{id: string, title: string, domain: string, chunks: string[]}>
     */
    private static function parseSources(string $message): array
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

            if ($title !== '' || $domain !== '' || $chunks !== []) {
                $sources[] = ['id' => $s[1], 'title' => $title, 'domain' => $domain, 'chunks' => $chunks];
            }
        }
        return $sources;
    }

    private static function unescape(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
