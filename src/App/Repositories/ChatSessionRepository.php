<?php

namespace App\Repositories;

class ChatSessionRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getAllDesc(): array
    {
        return $this->db->query("SELECT * FROM chat_sessions ORDER BY id DESC");
    }

    public function getHistory(int $sessionId): array
    {
        return $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
    }

    public function delete(int $id): void
    {
        $this->db->query("DELETE FROM chat_sessions WHERE id = :id", [':id' => $id]);
    }

    public function deleteMultiple(array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query("DELETE FROM chat_sessions WHERE id IN ($placeholders)", $ids);
    }

    public function create(string $title): int
    {
        $this->db->insert('chat_sessions', ['title' => $title]);
        return (int)$this->db->getConnection()->lastInsertId();
    }

    public function truncateAll(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("TRUNCATE TABLE chat_history");
        $this->db->query("TRUNCATE TABLE chat_sessions");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}