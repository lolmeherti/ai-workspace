<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $host = getenv('DB_HOST') ?: 'mysql';
        $db   = getenv('DB_NAME') ?: 'ai_memories';
        $user = getenv('DB_USER') ?: 'ai_user';
        $pass = getenv('DB_PASS') ?: 'secret123';
        
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function initTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                role ENUM('user', 'assistant', 'system') NOT NULL,
                message TEXT NOT NULL,
                image_path VARCHAR(255) NULL,
                token_estimate INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_chat_history_session_id FOREIGN KEY (session_id) 
                    REFERENCES chat_sessions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS memories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                memory_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function nukeAndRebuildTables(): void
    {
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $this->pdo->exec("DROP TABLE IF EXISTS chat_history;");
        $this->pdo->exec("DROP TABLE IF EXISTS chat_sessions;");
        $this->pdo->exec("DROP TABLE IF EXISTS memories;");
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        $this->initTables();
    }

    public function insert(string $table, array $data): bool
    {
        $columns      = implode(',', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $stmt = $this->pdo->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})");

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        return $stmt->execute();
    }

    public function selectSafe(string $table, array $conditions = []): array
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $sql = "SELECT * FROM `$table`";
        $binds = [];

        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $key => $value) {
                $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $whereClauses[] = "`$cleanKey` = :$cleanKey";
                $binds[":$cleanKey"] = $value;
            }
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($binds);
        
        return $stmt->fetchAll();
    }

    public function update(string $table, array $data, array $conditions): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        if (empty($data) || empty($conditions)) {
            return false;
        }

        $setClauses = [];
        $whereClauses = [];
        $binds = [];

        foreach ($data as $key => $value) {
            $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $setClauses[] = "`$cleanKey` = :set_$cleanKey";
            $binds[":set_$cleanKey"] = $value;
        }

        foreach ($conditions as $key => $value) {
            $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $whereClauses[] = "`$cleanKey` = :where_$cleanKey";
            $binds[":where_$cleanKey"] = $value;
        }

        $sets = implode(', ', $setClauses);
        $where = implode(' AND ', $whereClauses);

        $sql = "UPDATE `$table` SET $sets WHERE $where";
        $stmt = $this->pdo->prepare($sql);

        foreach ($binds as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value);
        }

        return $stmt->execute();
    }
}