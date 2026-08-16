<?php

namespace App\Jobs;

class JobRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function insert(array $job): string
    {
        $job['uuid'] = $job['uuid'] ?? Uuid::v4();
        $job = $this->encodeJsonFields($job);
        $this->db->insert('jobs', $job);
        return $job['uuid'];
    }

    public function update(string $uuid, array $data): void
    {
        $data = $this->encodeJsonFields($data);
        $this->db->update('jobs', $data, ['uuid' => $uuid]);
    }

    public function getByUuid(string $uuid): ?array
    {
        $rows = $this->db->query('SELECT * FROM jobs WHERE uuid = :uuid', [':uuid' => $uuid]);
        return $rows[0] ?? null;
    }

    public function findByUrlTimestamp(string $url, string $postedAt): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM jobs WHERE url = :url AND posted_at = :posted_at LIMIT 1',
            [':url' => $url, ':posted_at' => $postedAt]
        );
        return $rows[0] ?? null;
    }

    public function listByState(string $state, int $page = 1, int $perPage = 10): array
    {
        $order = $this->orderForState($state);
        $offset = max(0, ($page - 1) * $perPage);

        return $this->db->query(
            "SELECT * FROM jobs WHERE state = :state ORDER BY {$order} LIMIT :limit OFFSET :offset",
            [':state' => $state, ':limit' => $perPage, ':offset' => $offset]
        );
    }

    public function countByState(string $state): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM jobs WHERE state = :state', [':state' => $state]);
        return (int)($rows[0]['c'] ?? 0);
    }

    public function countsByState(): array
    {
        $rows = $this->db->query('SELECT state, COUNT(*) AS c FROM jobs GROUP BY state');
        $out = [
            'unread' => 0,
            'interested' => 0,
            'applied' => 0,
            'interview' => 0,
            'offer' => 0,
            'history' => 0,
        ];
        foreach ($rows as $row) {
            if (array_key_exists($row['state'], $out)) {
                $out[$row['state']] = (int)$row['c'];
            }
        }
        return $out;
    }

    public function deleteAll(): int
    {
        $stmt = $this->db->executeStatement('DELETE FROM jobs');
        return $stmt->rowCount();
    }

    public function deleteMany(array $uuids): int
    {
        if (empty($uuids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $stmt = $this->db->executeStatement("DELETE FROM jobs WHERE uuid IN ({$placeholders})", $uuids);
        return $stmt->rowCount();
    }

    public function deleteUnreadByDomain(string $domain): int
    {
        $stmt = $this->db->executeStatement(
            "DELETE FROM jobs WHERE state = 'unread' AND source_domain = :domain",
            [':domain' => $domain]
        );
        return $stmt->rowCount();
    }

    public function deleteStaleUnread(int $days = 14): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->db->executeStatement(
            "DELETE FROM jobs WHERE state = 'unread' AND posted_at < :cutoff",
            [':cutoff' => $cutoff]
        );
        return $stmt->rowCount();
    }

    public function getAllByState(string $state): array
    {
        $order = $this->orderForState($state);
        return $this->db->query(
            "SELECT * FROM jobs WHERE state = :state ORDER BY {$order}",
            [':state' => $state]
        );
    }

    public function updateState(string $uuid, array $job): void
    {
        $columns = [
            'state', 'state_timestamps', 'history_reason',
            'interested_at', 'applied_at', 'interview_at', 'offer_at', 'history_at',
            'applied_cv_uuid', 'applied_cv_markdown',
        ];
        $this->update($uuid, array_intersect_key($job, array_flip($columns)));
    }

    public function decodeRow(array $job): array
    {
        foreach (['metadata', 'state_timestamps', 'interview_timestamps'] as $field) {
            if (isset($job[$field]) && is_string($job[$field]) && $job[$field] !== '') {
                $decoded = json_decode($job[$field], true);
                if (is_array($decoded)) {
                    $job[$field] = $decoded;
                }
            }
        }
        return $job;
    }

    private function orderForState(string $state): string
    {
        return match ($state) {
            'unread' => 'posted_at DESC',
            'interested' => 'interested_at DESC',
            'applied' => 'applied_at DESC',
            'interview' => 'interview_at DESC',
            'offer' => 'offer_at DESC',
            'history' => 'history_at DESC',
            default => 'posted_at DESC',
        };
    }

    private function encodeJsonFields(array $job): array
    {
        foreach (['metadata', 'state_timestamps', 'interview_timestamps'] as $field) {
            if (array_key_exists($field, $job) && is_array($job[$field])) {
                $job[$field] = json_encode($job[$field]);
            }
        }
        return $job;
    }
}
