<?php

namespace App;

use PDO;
use PDOException;
use InvalidArgumentException;
use App\Config;

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
            throw new PDOException("Database Connection Failed: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    private function executeStatement(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $type = PDO::PARAM_STR;
                if (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                }

                $bindKey = is_int($key) ? $key + 1 : $key;
                
                $stmt->bindValue($bindKey, $value, $type);
            }

            $stmt->execute();
            return $stmt;

        } catch (PDOException $e) {
            $logFile = Config::getProjectRoot() . '/db_errors.log'; 
            $date = date('Y-m-d H:i:s');
            $errorMessage = $e->getMessage();
            $paramsString = print_r($params, true);
            $traceString = $e->getTraceAsString();

            $logEntry = <<<TEXT
================ DB ERROR ================
Date: {$date}
Message: {$errorMessage}
SQL: {$sql}
Params: {$paramsString}
Trace: {$traceString}
==========================================

TEXT;

            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            throw $e;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->executeStatement($sql, $params);
        return $stmt->fetchAll() ?: [];
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function initTables(): void
    {
        $this->executeStatement("
            CREATE TABLE IF NOT EXISTS chat_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->executeStatement("
            CREATE TABLE IF NOT EXISTS chat_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                role ENUM('user', 'assistant', 'system') NOT NULL,
                message LONGTEXT NOT NULL,
                image_path VARCHAR(255) NULL,
                token_estimate INT DEFAULT 0,
                search_query VARCHAR(255) NULL,
                cache_used TINYINT(1) DEFAULT 0,
                scraped_urls TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_chat_history_session_id
                    FOREIGN KEY (session_id)
                    REFERENCES chat_sessions(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->executeStatement("
            CREATE TABLE IF NOT EXISTS memories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                memory_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FULLTEXT INDEX ft_memory_text (memory_text)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function nukeAndRebuildTables(): void
    {
        $this->executeStatement("SET FOREIGN_KEY_CHECKS = 0;");
        $this->executeStatement("DROP TABLE IF EXISTS chat_history;");
        $this->executeStatement("DROP TABLE IF EXISTS chat_sessions;");
        $this->executeStatement("DROP TABLE IF EXISTS memories;");
        $this->executeStatement("SET FOREIGN_KEY_CHECKS = 1;");

        $this->initTables();
    }

    private function assertIdentifier(string $name, string $type): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Invalid $type name: $name");
        }
    }

    public function insert(string $table, array $data): bool
    {
        $this->assertIdentifier($table, 'table');
        
        if (empty($data)) {
            throw new InvalidArgumentException("Insert data cannot be empty");
        }

        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $key => $value) {
            $this->assertIdentifier($key, 'column');
            $columns[] = "`$key`";
            $placeholders[] = ":$key";
            $params[":$key"] = $value;
        }

        $sql = "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        
        $this->executeStatement($sql, $params);
        return true;
    }

    public function selectSafe(string $table, array $conditions = []): array
    {
        $this->assertIdentifier($table, 'table');

        $sql = "SELECT * FROM `$table`";
        $params = [];

        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $this->assertIdentifier($key, 'column');
                $where[] = "`$key` = :$key";
                $params[":$key"] = $value;
            }
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->query($sql, $params);
    }

    public function update(string $table, array $data, array $conditions): bool
    {
        $this->assertIdentifier($table, 'table');
        
        if (empty($data) || empty($conditions)) {
            throw new InvalidArgumentException("Update requires data and conditions");
        }

        $set = [];
        $where = [];
        $params = [];

        foreach ($data as $key => $value) {
            $this->assertIdentifier($key, 'column');
            $set[] = "`$key` = :set_$key";
            $params[":set_$key"] = $value;
        }

        foreach ($conditions as $key => $value) {
            $this->assertIdentifier($key, 'column');
            $where[] = "`$key` = :where_$key";
            $params[":where_$key"] = $value;
        }

        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);

        $this->executeStatement($sql, $params);
        return true;
    }
}