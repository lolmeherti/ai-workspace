<?php

namespace App\Search;

use App\Database;
use App\Database\FileAliasMap;

class FileRetriever
{
    public const DEFAULT_LIMIT = 3;

    public function __construct(
        private Database $db,
        private ?FileAliasMap $aliasMap = null,
        private ?StructuralChunker $chunker = null,
        private ?Bm25Retriever $bm25 = null,
    ) {
        $this->aliasMap ??= new FileAliasMap();
        $this->chunker ??= new StructuralChunker();
        $this->bm25 ??= new Bm25Retriever();
    }

    public function rank(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        return array_slice($this->rankAll($query), 0, $limit);
    }

    public function rankAll(string $query): array
    {
        $records = $this->loadSearchRecords();
        if (empty($records)) {
            return [];
        }

        $chunks = [];
        foreach ($records as $record) {
            foreach ($this->chunksForRecord($record) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        $scored = $this->bm25->scoreFileChunks($chunks, $this->aliasMap->expand($query));

        $byFile = [];
        foreach ($scored as $entry) {
            $fileId = $entry['chunk']->fileId;
            $byFile[$fileId] = max($byFile[$fileId] ?? 0, $entry['score']);
        }
        arsort($byFile);

        $indexed = [];
        foreach ($records as $record) {
            $indexed[(int)$record['id']] = $record;
        }

        $result = [];
        foreach (array_keys($byFile) as $fileId) {
            if (!isset($indexed[$fileId])) {
                continue;
            }
            $r = $indexed[$fileId];
            $result[] = [
                'id' => $r['id'],
                'original_name' => $r['original_name'],
                'physical_name' => $r['physical_name'],
                'generated_title' => $r['generated_title'],
                'file_type' => $r['file_type'],
                'uploaded_at' => $r['uploaded_at'],
            ];
        }

        return $result;
    }

    private function chunksForRecord(array $record): array
    {
        $title = trim(($record['generated_title'] ?? '') . ' ' . ($record['original_name'] ?? ''));
        $entities = $this->decodeEntities($record['search_entities'] ?? null);
        $texts = $this->chunker->chunkText((string)($record['searchable_text'] ?? ''));

        $chunks = [];
        $position = 0;
        foreach ($texts as $text) {
            $position++;
            $chunks[] = new FileChunk(
                fileId: (int)$record['id'],
                title: $title,
                entities: $entities,
                text: $text,
                position: $position,
            );
        }
        return $chunks;
    }

    private function loadSearchRecords(): array
    {
        return $this->db->query(
            "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at, search_entities, searchable_text
             FROM uploaded_files
             WHERE searchable_text IS NOT NULL AND searchable_text <> ''"
        );
    }

    private function decodeEntities(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }
}
