<?php

namespace App\Services;

use App\Database;
use Webklex\PHPIMAP\ClientManager;

class EmailService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function fetchRecentEmails(bool $includeSeen): array
    {
        $accounts = $this->db->query("SELECT * FROM email_accounts");
        if (empty($accounts)) {
            return [];
        }

        $this->ensureCacheTableExists();

        $allEmails = [];
        $cm = new ClientManager();

        foreach ($accounts as $account) {
            try {
                $host = $account['imap_host'];
                $port = $account['imap_port'] ?: 993;

                $client = $cm->make([
                    'host'          => $host,
                    'port'          => $port,
                    'encryption'    => 'ssl',
                    'validate_cert' => true,
                    'username'      => $account['email_address'],
                    'password'      => $account['app_password'],
                    'protocol'      => 'imap',
                    'timeout'       => 5,
                    'options'       => [
                        'timeout' => 5
                    ]
                ]);

                $client->connect();
                $inbox = $client->getFolder('INBOX');
                if (!$inbox) {
                    $folders = $client->getFolders();
                    if (!empty($folders)) {
                        $inbox = $folders[0];
                    }
                }

                if ($inbox) {
                    $queryBuilder = $inbox->query()->since(new \DateTime("-24 hours"));
                    
                    if (!$includeSeen) {
                        $queryBuilder->unseen();
                    }

                    $messages = $queryBuilder->limit(10)->get();

                    foreach ($messages as $msg) {
                        $subject = (string)$msg->getSubject();
                        if (!empty($subject)) {
                            $decodedSubject = @mb_decode_mimeheader($subject);
                            if ($decodedSubject !== false) {
                                $subject = $decodedSubject;
                            }
                        }

                        $bodyHtml = $msg->getHTMLBody();
                        if (empty($bodyHtml)) {
                            $bodyHtml = nl2br(htmlspecialchars((string)$msg->getTextBody()));
                        }

                        $bodyText = strip_tags($bodyHtml);
                        $bodyText = preg_replace('/\s+/', ' ', $bodyText);
                        $bodyText = trim($bodyText);
                        if (mb_strlen($bodyText) > 500) {
                            $bodyText = mb_substr($bodyText, 0, 500) . '...';
                        }

                        $fromName = '';
                        try {
                            $fromObj = $msg->getFrom()->first();
                            if ($fromObj) {
                                $personal = (string)$fromObj->personal;
                                $mail = (string)$fromObj->mail;
                                if (!empty($personal)) {
                                    $fromName = trim($personal, '"\'');
                                    if (!empty($mail)) {
                                        $fromName .= ' <' . $mail . '>';
                                    }
                                } else {
                                    $fromName = $mail;
                                }
                            } else {
                                $fromName = (string)$msg->getFrom();
                            }
                        } catch (\Throwable $errFrom) {
                            $fromName = (string)$msg->getFrom();
                        }
                        $fromName = trim(@mb_decode_mimeheader($fromName) ?: $fromName, '"\'');

                        $date = '';
                        try {
                            $date = (string)$msg->getDate();
                        } catch (\Throwable $errDate) {
                            $date = 'Unknown Date';
                        }

                        $uid = (string)$msg->getUid();

                        $sanitizedSubject = mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
                        $sanitizedFromName = mb_convert_encoding($fromName, 'UTF-8', 'UTF-8');
                        $sanitizedBody = mb_convert_encoding($bodyHtml, 'UTF-8', 'UTF-8');
                        $sanitizedSnippet = mb_convert_encoding($bodyText, 'UTF-8', 'UTF-8');

                        try {
                            $this->db->query("INSERT INTO email_cache (account_id, uid, subject, from_name, date_str, body, snippet) 
                                VALUES (:account_id, :uid, :subject, :from_name, :date_str, :body, :snippet)
                                ON DUPLICATE KEY UPDATE 
                                subject = :u_subject, from_name = :u_from_name, date_str = :u_date_str, body = :u_body, snippet = :u_snippet", [
                                ':account_id'  => $account['id'],
                                ':uid'         => $uid,
                                ':subject'     => $sanitizedSubject,
                                ':from_name'   => $sanitizedFromName,
                                ':date_str'    => $date,
                                ':body'        => $sanitizedBody,
                                ':snippet'     => $sanitizedSnippet,
                                ':u_subject'   => $sanitizedSubject,
                                ':u_from_name' => $sanitizedFromName,
                                ':u_date_str'  => $date,
                                ':u_body'      => $sanitizedBody,
                                ':u_snippet'   => $sanitizedSnippet
                            ]);
                        } catch (\Throwable $errCacheWrite) {
                        }

                        $allEmails[] = [
                            'account_id'    => $account['id'],
                            'account_label' => $account['label'],
                            'account_email' => $account['email_address'],
                            'from'          => $fromName,
                            'subject'       => $subject,
                            'date'          => $date,
                            'snippet'       => $bodyText,
                            'uid'           => $uid
                        ];

                        if (!$includeSeen) {
                            try {
                                $msg->setFlag('Seen');
                            } catch (\Throwable $errFlag) {
                                try {
                                    $msg->markAsRead();
                                } catch (\Throwable $errRead) {
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $allEmails[] = [
                    'account_label' => $account['label'],
                    'account_email' => $account['email_address'],
                    'error'         => $e->getMessage()
                ];
            }
        }

        return $allEmails;
    }

    private function ensureCacheTableExists(): void
    {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS email_cache (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (\Throwable $e) {
        }
    }
}