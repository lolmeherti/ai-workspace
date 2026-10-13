<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * What the database says is owed.
 *
 * Three facts make work owed, and each one is a plain query. No status column, no queue state in MySQL: the queue
 * holds the work list, these rows hold the truth it is derived from.
 *
 *   turns()        a user row with no terminal row (turn_answers)          → a turn is owed
 *   atomization()  a fetched row with no atoms yet (atomic_context NULL)   → notes are owed for it
 *   batches()      rows held as 'queued' in a session that is not blocked  → a batch is owed
 *
 * Each list is ordered oldest-first by row id, which is the order the queue consumes in.
 */
final class OwedWork
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<int,array{row_id:int,session_id:int}>
     */
    public function turns(?int $sessionId = null): array
    {
        $sql = "SELECT h.id AS row_id, h.session_id
                  FROM chat_history h
             LEFT JOIN turn_answers a ON a.user_row_id = h.id
                 WHERE h.role = 'user'
                   AND (h.message_type IS NULL OR h.message_type = 'text')
                   AND a.user_row_id IS NULL";
        $params = [];
        if ($sessionId !== null) {
            $sql .= " AND h.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= $this->notBlocked('h.session_id');
        $sql .= " ORDER BY h.id";

        return $this->map($this->db->query($sql, $params));
    }

    /**
     * @return array<int,array{row_id:int,session_id:int}>
     */
    public function atomization(?int $sessionId = null): array
    {
        $sql = "SELECT h.id AS row_id, h.session_id
                  FROM chat_history h
                 WHERE h.message_type = 'data_fetching'
                   AND h.atomic_context IS NULL";
        $params = [];
        if ($sessionId !== null) {
            $sql .= " AND h.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= $this->notBlocked('h.session_id');
        $sql .= " ORDER BY h.id";

        return $this->map($this->db->query($sql, $params));
    }

    /**
     * @return array<int,array{row_id:int,session_id:int}>
     */
    public function batches(?int $sessionId = null): array
    {
        $sql = "SELECT h.id AS row_id, h.session_id
                  FROM chat_history h
                 WHERE h.message_type = 'queued'";
        $params = [];
        if ($sessionId !== null) {
            $sql .= " AND h.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= $this->notBlocked('h.session_id');
        $sql .= " ORDER BY h.id";

        return $this->map($this->db->query($sql, $params));
    }

    /**
     * Sessions the user stopped. The list lives in the database (rulings R12) and arrives with that ruling's build;
     * every derivation reads it through here, so nothing else changes when it lands.
     *
     * @return array<int,int>
     */
    public function blockedSessions(): array
    {
        return [];
    }

    private function notBlocked(string $column): string
    {
        $blocked = array_map('intval', $this->blockedSessions());
        if ($blocked === []) {
            return '';
        }

        return " AND {$column} NOT IN (" . implode(',', $blocked) . ")";
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{row_id:int,session_id:int}>
     */
    private function map(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'row_id' => (int)$row['row_id'],
            'session_id' => (int)$row['session_id'],
        ], $rows);
    }
}
