<?php

namespace App\Repositories;

class MemoryRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) as count FROM memories");
        return (int)($result[0]['count'] ?? 0);
    }

    public function getAllLimit500(): array
    {
        return $this->db->query("SELECT * FROM memories ORDER BY id DESC LIMIT 500");
    }

    public function add(string $text): void
    {
        $this->db->insert('memories', ['memory_text' => $text]);
    }

    public function delete(int $id): void
    {
        $this->db->query("DELETE FROM memories WHERE id = :id", [':id' => $id]);
    }

    public function deleteMultiple(array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query("DELETE FROM memories WHERE id IN ($placeholders)", $ids);
    }

    public function update(int $id, string $text): void
    {
        $this->db->update('memories', ['memory_text' => $text], ['id' => $id]);
    }
}