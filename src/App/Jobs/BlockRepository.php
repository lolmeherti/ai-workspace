<?php

namespace App\Jobs;

class BlockRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function add(string $kind, string $value, int $days = 7): void
    {
        $this->db->insert('job_blocks', [
            'kind' => $kind,
            'value' => $value,
            'expires_at' => date('Y-m-d H:i:s', strtotime("+{$days} days")),
        ]);
    }

    public function activeValues(string $kind): array
    {
        $rows = $this->db->query(
            'SELECT value FROM job_blocks WHERE kind = :kind AND expires_at > NOW()',
            [':kind' => $kind]
        );
        return array_map(fn($row) => $row['value'], $rows);
    }

    public function listActive(): array
    {
        $this->purgeExpired();
        return $this->db->query(
            'SELECT kind, value, expires_at FROM job_blocks WHERE expires_at > NOW() ORDER BY kind, value'
        );
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->executeStatement('DELETE FROM job_blocks WHERE expires_at <= NOW()');
        return $stmt->rowCount();
    }
}
