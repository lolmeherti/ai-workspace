<?php

namespace App\Repositories;

class AppSettingsRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function get(string $key, string $default = ''): string
    {
        $rows = $this->db->query(
            'SELECT setting_value FROM app_settings WHERE setting_key = ?',
            [$key]
        );
        return isset($rows[0]['setting_value']) ? (string)$rows[0]['setting_value'] : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->executeStatement(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }
}
