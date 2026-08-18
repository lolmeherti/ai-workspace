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
            CREATE TABLE IF NOT EXISTS user_profiles (
                id INT PRIMARY KEY,
                profile_text TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS chat_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                is_starred TINYINT(1) DEFAULT 0,
                context_tokens INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_sessions LIKE 'context_tokens'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_sessions ADD COLUMN context_tokens INT DEFAULT 0 AFTER is_starred");
            }
        } catch (PDOException $e) {
        }

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
                source_map JSON NULL,
                active_context TINYINT(1) DEFAULT 1,
                backing_chunks JSON NULL,
                atomic_context JSON NULL,
                perf_metrics JSON NULL,
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
                searchable_text LONGTEXT NULL,
                search_entities JSON NULL,
                search_index_version INT NOT NULL DEFAULT 0,
                file_type VARCHAR(50) NOT NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_uploaded_files_session_id
                    FOREIGN KEY (session_id)
                    REFERENCES chat_sessions(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM uploaded_files LIKE 'searchable_text'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE uploaded_files ADD COLUMN searchable_text LONGTEXT NULL AFTER generated_title");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM uploaded_files LIKE 'search_entities'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE uploaded_files ADD COLUMN search_entities JSON NULL AFTER searchable_text");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM uploaded_files LIKE 'search_index_version'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE uploaded_files ADD COLUMN search_index_version INT NOT NULL DEFAULT 0 AFTER search_entities");
            }
        } catch (PDOException $e) {
        }

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

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM email_cache LIKE 'fetched_at'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE email_cache ADD COLUMN fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_at");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'message_type'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN message_type VARCHAR(50) DEFAULT 'text' AFTER message");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'tool_name'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN tool_name VARCHAR(100) NULL AFTER message_type");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'source_map'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN source_map JSON NULL AFTER search_query");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'active_context'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN active_context TINYINT(1) DEFAULT 1 AFTER source_map");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'backing_chunks'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN backing_chunks JSON NULL AFTER active_context");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'atomic_context'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN atomic_context JSON NULL AFTER backing_chunks");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'selected_chunks'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN selected_chunks JSON NULL AFTER atomic_context");
            }
        } catch (PDOException $e) {
        }

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM chat_history LIKE 'perf_metrics'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE chat_history ADD COLUMN perf_metrics JSON NULL AFTER selected_chunks");
            }
        } catch (PDOException $e) {
        }

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS app_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                context JSON NULL,
                level ENUM('debug', 'info', 'warn', 'error') NOT NULL DEFAULT 'info',
                source VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS atomization_stats (
                id TINYINT PRIMARY KEY,
                consolidation_ms_ema DOUBLE NOT NULL DEFAULT 4500,
                samples INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_cvs (
                uuid CHAR(36) PRIMARY KEY,
                designation VARCHAR(255) NOT NULL,
                file_ref VARCHAR(255) NOT NULL,
                file_hash VARCHAR(64) NOT NULL,
                extracted_markdown LONGTEXT NULL,
                active_flag TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_profile (
                id INT PRIMARY KEY,
                locations JSON NULL,
                work_modes JSON NULL,
                employment_types JSON NULL,
                salary_min DECIMAL(12,2) NULL,
                salary_currency CHAR(3) NULL,
                free_text TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_registry (
                uuid CHAR(36) PRIMARY KEY,
                domain VARCHAR(190) NOT NULL,
                type ENUM('static','dynamic') NOT NULL,
                url VARCHAR(2048) NOT NULL,
                config_json JSON NULL,
                placeholders JSON NULL,
                dedupe_key CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_registry_dedupe (dedupe_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $columns = $this->db->query("SHOW COLUMNS FROM job_registry LIKE 'placeholders'");
            if (empty($columns)) {
                $this->db->executeStatement("ALTER TABLE job_registry ADD COLUMN placeholders JSON NULL AFTER config_json");
            }
        } catch (PDOException $e) {
        }

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS jobs (
                uuid CHAR(36) PRIMARY KEY,
                source_domain VARCHAR(255) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                posted_at DATETIME NOT NULL,
                title VARCHAR(255) NOT NULL,
                company VARCHAR(255) NOT NULL,
                description LONGTEXT NOT NULL,
                location VARCHAR(255) NULL,
                city VARCHAR(255) NULL,
                country VARCHAR(255) NULL,
                work_mode ENUM('remote','hybrid','on_site') NULL,
                employment_type VARCHAR(50) NULL,
                salary VARCHAR(255) NULL,
                applicant_count VARCHAR(50) NULL,
                metadata JSON NULL,
                ai_selection_comment TEXT NULL,
                state ENUM('unread','interested','applied','interview','offer','history') NOT NULL DEFAULT 'unread',
                history_reason ENUM('not_interested','rejected_by_company','offer_rejected','offer_accepted') NULL,
                state_timestamps JSON NULL,
                interested_at DATETIME NULL,
                applied_at DATETIME NULL,
                applied_cv_uuid CHAR(36) NULL,
                applied_cv_markdown LONGTEXT NULL,
                interview_at DATETIME NULL,
                interview_timestamps JSON NULL,
                offer_at DATETIME NULL,
                offer_compensation VARCHAR(255) NULL,
                offer_deadline DATETIME NULL,
                offer_notes TEXT NULL,
                history_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_jobs_state (state),
                INDEX idx_jobs_posted_at (posted_at),
                INDEX idx_jobs_source_domain (source_domain),
                INDEX idx_jobs_company (company)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kind ENUM('domain','company') NOT NULL,
                value VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_job_blocks_kind_value (kind, value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_runs (
                uuid CHAR(36) PRIMARY KEY,
                selected_cv_uuid CHAR(36) NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                profile_snapshot JSON NULL,
                generated_queries JSON NULL,
                jobs_scraped INT DEFAULT 0,
                jobs_selected INT DEFAULT 0,
                sources_attempted INT DEFAULT 0,
                sources_failed INT DEFAULT 0,
                status ENUM('running','completed','cancelled') DEFAULT 'running'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS job_run_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_uuid CHAR(36) NOT NULL,
                level VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                context JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_job_run_logs_run (run_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
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
        $this->db->executeStatement("DROP TABLE IF EXISTS app_events;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_run_logs;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_runs;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_blocks;");
        $this->db->executeStatement("DROP TABLE IF EXISTS jobs;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_registry;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_profile;");
        $this->db->executeStatement("DROP TABLE IF EXISTS job_cvs;");
        $this->db->executeStatement("SET FOREIGN_KEY_CHECKS = 1;");

        $this->initTables();
    }
}
