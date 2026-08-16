<?php

namespace App\Jobs;

class CvRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create(string $designation, string $fileRef, string $fileHash): string
    {
        $uuid = Uuid::v4();
        $this->db->insert('job_cvs', [
            'uuid' => $uuid,
            'designation' => $designation,
            'file_ref' => $fileRef,
            'file_hash' => $fileHash,
        ]);
        return $uuid;
    }

    public function getByUuid(string $uuid): ?array
    {
        $rows = $this->db->query('SELECT * FROM job_cvs WHERE uuid = :uuid', [':uuid' => $uuid]);
        return $rows[0] ?? null;
    }

    public function listSelectable(): array
    {
        return $this->db->query(
            'SELECT * FROM job_cvs WHERE deleted_at IS NULL ORDER BY created_at DESC'
        );
    }

    public function getActive(): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM job_cvs WHERE active_flag = 1 AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    public function updateDesignation(string $uuid, string $designation): void
    {
        $this->db->update('job_cvs', ['designation' => $designation], ['uuid' => $uuid]);
    }

    public function updateMarkdown(string $uuid, string $markdown): void
    {
        $this->db->update('job_cvs', ['extracted_markdown' => $markdown], ['uuid' => $uuid]);
    }

    public function setActive(string $uuid, bool $active): void
    {
        $this->db->query('UPDATE job_cvs SET active_flag = 0 WHERE active_flag = 1');
        $this->db->update('job_cvs', ['active_flag' => $active ? 1 : 0], ['uuid' => $uuid]);
    }

    public function softDelete(string $uuid): void
    {
        $this->db->update('job_cvs', ['deleted_at' => date('Y-m-d H:i:s'), 'active_flag' => 0], ['uuid' => $uuid]);
    }
}
