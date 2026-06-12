<?php

namespace App\Database;

use App\Database as DatabaseConnection;
use PDOException;

class Schema
{
    public function __construct(private DatabaseConnection $db)
    {
    }

    public function initTables(): void
    {
        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS chat_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                is_starred TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
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

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS memories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                memory_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FULLTEXT INDEX ft_memory_text (memory_text)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS uploaded_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NULL,
                original_name VARCHAR(255) NOT NULL,
                physical_name VARCHAR(255) NOT NULL,
                generated_title TEXT NOT NULL, 
                file_type VARCHAR(50) NOT NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_uploaded_files_session_id
                    FOREIGN KEY (session_id)
                    REFERENCES chat_sessions(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS email_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(255) NOT NULL,
                provider VARCHAR(50) NOT NULL,
                email_address VARCHAR(255) NOT NULL,
                app_password TEXT NOT NULL,
                imap_host VARCHAR(255) NULL,
                imap_port INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS email_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                uid VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NULL,
                from_name VARCHAR(255) NULL,
                date_str VARCHAR(255) NULL,
                body LONGTEXT NULL,
                snippet TEXT NULL,
                is_seen TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_email (account_id, uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM email_cache LIKE 'is_seen'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE email_cache ADD COLUMN is_seen TINYINT(1) DEFAULT 0 AFTER snippet");
            }
        } catch (PDOException $e) {
        }
    }

    public function nukeAndRebuild(): void
    {
        $this->db->executeStatement("SET FOREIGN_KEY_CHECKS = 0;");
        $this->db->executeStatement("DROP TABLE IF EXISTS chat_history;");
        $this->db->executeStatement("DROP TABLE IF EXISTS chat_sessions;");
        $this->db->executeStatement("DROP TABLE IF EXISTS memories;");
        $this->db->executeStatement("DROP TABLE IF EXISTS uploaded_files;");
        $this->db->executeStatement("DROP TABLE IF EXISTS email_accounts;");
        $this->db->executeStatement("DROP TABLE IF EXISTS email_cache;");
        $this->db->executeStatement("SET FOREIGN_KEY_CHECKS = 1;");

        $this->initTables();
    }
}
