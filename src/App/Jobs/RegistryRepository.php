<?php

namespace App\Jobs;

class RegistryRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function listAll(): array
    {
        return $this->decodeRows($this->db->query('SELECT * FROM job_registry ORDER BY created_at ASC, uuid ASC'));
    }

    public function getByUuid(string $uuid): ?array
    {
        $rows = $this->db->query('SELECT * FROM job_registry WHERE uuid = :uuid', [':uuid' => $uuid]);
        return $rows === [] ? null : $this->decodeRow($rows[0]);
    }

    public function addTemplate(string $urlTemplate, array $placeholders): array
    {
        $dedupeKey = self::templateDedupeKey($urlTemplate, $placeholders);

        $existing = $this->db->query(
            'SELECT uuid FROM job_registry WHERE dedupe_key = :key LIMIT 1',
            [':key' => $dedupeKey]
        );
        if (!empty($existing)) {
            return ['created' => false, 'uuid' => $existing[0]['uuid']];
        }

        $uuid = Uuid::v4();
        $this->db->insert('job_registry', [
            'uuid' => $uuid,
            'domain' => self::domainFromUrl($urlTemplate),
            'type' => 'dynamic',
            'url' => $urlTemplate,
            'placeholders' => self::encodePlaceholders($placeholders),
            'dedupe_key' => $dedupeKey,
        ]);
        return ['created' => true, 'uuid' => $uuid];
    }

    public function updateTemplate(string $uuid, string $urlTemplate, array $placeholders): array
    {
        $dedupeKey = self::templateDedupeKey($urlTemplate, $placeholders);

        $clash = $this->db->query(
            'SELECT uuid FROM job_registry WHERE dedupe_key = :key AND uuid <> :uuid LIMIT 1',
            [':key' => $dedupeKey, ':uuid' => $uuid]
        );
        if (!empty($clash)) {
            return ['updated' => false, 'reason' => 'duplicate'];
        }

        $this->db->update('job_registry', [
            'domain' => self::domainFromUrl($urlTemplate),
            'type' => 'dynamic',
            'url' => $urlTemplate,
            'placeholders' => self::encodePlaceholders($placeholders),
            'dedupe_key' => $dedupeKey,
        ], ['uuid' => $uuid]);

        return ['updated' => true];
    }

    public function delete(string $uuid): void
    {
        $this->db->query('DELETE FROM job_registry WHERE uuid = :uuid', [':uuid' => $uuid]);
    }

    private function decodeRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i] = $this->decodeRow($row);
        }
        return $rows;
    }

    private function decodeRow(array $row): array
    {
        if (isset($row['placeholders']) && is_string($row['placeholders']) && $row['placeholders'] !== '') {
            $decoded = json_decode($row['placeholders'], true);
            $row['placeholders'] = is_array($decoded) ? $decoded : [];
        } elseif (!isset($row['placeholders']) || $row['placeholders'] === null) {
            $row['placeholders'] = [];
        }
        return $row;
    }

    public static function encodePlaceholders(array $placeholders): ?string
    {
        $clean = [];
        foreach ($placeholders as $name => $values) {
            if (is_array($values) && $values !== []) {
                $clean[$name] = array_values($values);
            }
        }
        return $clean === [] ? null : json_encode($clean);
    }

    public static function domainFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : '';
    }

    public static function templateDedupeKey(string $urlTemplate, array $placeholders): string
    {
        $copy = $placeholders;
        ksort($copy);
        return hash('sha256', implode('|', [self::normalizeUrl($urlTemplate), json_encode($copy)]));
    }

    private static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
