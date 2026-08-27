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

    public function fetchRecentEmails(bool $includeSeen, ?callable $onAccountFetch = null): array
    {
        $accounts = $this->db->query("SELECT * FROM email_accounts");
        if (empty($accounts)) {
            return [];
        }

        $allEmails = [];
        $cm = new ClientManager();

        foreach ($accounts as $account) {
            if ($onAccountFetch !== null) {
                $onAccountFetch($account['email_address'], $account['label']);
            }

            $client = null;
            try {
                $host = $account['imap_host'];
                $port = $account['imap_port'] ?: 993;

                $client = $cm->make([
                    'host'          => $host,
                    'port'          => $port,
                    'encryption'    => 'ssl',
                    'validate_cert' => true,
                    'username'      => $account['email_address'],
                    'password' => $account['app_password'],
                    'protocol'      => 'imap',
                    'timeout'       => 5,
                    'options'       => [
                        'timeout' => 5
                    ]
                ]);

                $this->connectWithRetry($client);
                
                $inbox = $client->getFolder('INBOX');
                
                if (!$inbox) {
                    try {
                        $inbox = $client->getFolderByName('INBOX');
                    } catch (\Throwable $_) {}
                }
                
                if (!$inbox) {
                    try {
                        $flatFolders = $client->getFolders(false);
                        foreach ($flatFolders as $folder) {
                            if (strcasecmp($folder->name, 'INBOX') === 0 || strcasecmp($folder->path, 'INBOX') === 0) {
                                $inbox = $folder;
                                break;
                            }
                        }
                    } catch (\Throwable $_) {}
                }

                if ($inbox) {
                    $queryBuilder = $inbox->query()->since(new \DateTime("-24 hours"));
                    
                    if (!$includeSeen) {
                        $queryBuilder->unseen();
                    }

                    $queryBuilder->limit(10);

                    // PHPIMAP reconnects transparently when the IMAP socket idles
                    // out (getConnection()->checkConnection()), which resets the
                    // client's active_folder to null. If that happens mid-fetch,
                    // Message::make() assigns null to the typed $folder_path
                    // property and throws a TypeError. Re-select the folder and
                    // retry so one idle socket doesn't kill the whole account.
                    $messages = null;
                    for ($attempt = 0; $attempt < 3 && $messages === null; $attempt++) {
                        try {
                            $client->openFolder($inbox->path);
                            $messages = $queryBuilder->get();
                        } catch (\TypeError $e) {
                            if ($attempt === 2) {
                                throw $e;
                            }
                        }
                    }

                    $messagesArray = [];
                    foreach ($messages as $msg) {
                        $messagesArray[] = $msg;
                    }

                    usort($messagesArray, function ($a, $b) {
                        $tA = 0; $tB = 0;
                        try {
                            $tA = $a->getDate()->toDate()->getTimestamp();
                        } catch (\Throwable $_) {
                            try {
                                $tA = strtotime((string)$a->getDate()) ?: 0;
                            } catch (\Throwable $_) {}
                        }
                        try {
                            $tB = $b->getDate()->toDate()->getTimestamp();
                        } catch (\Throwable $_) {
                            try {
                                $tB = strtotime((string)$b->getDate()) ?: 0;
                            } catch (\Throwable $_) {}
                        }
                        return $tB <=> $tA;
                    });

                    foreach ($messagesArray as $msg) {
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
                        if (empty(strip_tags((string)$bodyHtml))) {
                            foreach (($msg->bodies ?? []) as $content) {
                                if (!empty(trim((string)$content))) {
                                    $bodyHtml = (string)$content;
                                    break;
                                }
                            }
                        }
                        if (empty(strip_tags((string)$bodyHtml))) {
                            $raw = $msg->getRawBody();
                            if (!empty($raw)) {
                                $raw = preg_replace('/^.*?\R\R/s', '', $raw);
                                $raw = preg_replace('/\RContent-.*?\R\R/si', "\n\n", $raw);
                                $raw = preg_replace('/\R--\w+/s', '', $raw);
                                $raw = trim($raw);
                                if (!empty($raw)) {
                                    $bodyHtml = nl2br(htmlspecialchars($raw));
                                }
                            }
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

                        $isSeen = false;
                        try {
                            $isSeen = $msg->hasFlag('seen') || $msg->getFlags()->has('seen');
                        } catch (\Throwable $_) {
                            $isSeen = false;
                        }

                        $sanitizedSubject = mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
                        $sanitizedFromName = mb_convert_encoding($fromName, 'UTF-8', 'UTF-8');
                        $sanitizedBody = mb_convert_encoding($bodyHtml, 'UTF-8', 'UTF-8');
                        $sanitizedSnippet = mb_convert_encoding($bodyText, 'UTF-8', 'UTF-8');

                        try {
                            $this->db->query("INSERT INTO email_cache (account_id, uid, subject, from_name, date_str, body, snippet, is_seen) 
                                VALUES (:account_id, :uid, :subject, :from_name, :date_str, :body, :snippet, :is_seen)
                                ON DUPLICATE KEY UPDATE 
                                subject = :u_subject, from_name = :u_from_name, date_str = :u_date_str, body = :u_body, snippet = :u_snippet, is_seen = :u_is_seen", [
                                ':account_id'  => $account['id'],
                                ':uid'         => $uid,
                                ':subject'     => $sanitizedSubject,
                                ':from_name'   => $sanitizedFromName,
                                ':date_str'    => $date,
                                ':body'        => $sanitizedBody,
                                ':snippet'     => $sanitizedSnippet,
                                ':is_seen'     => $isSeen ? 1 : 0,
                                ':u_subject'   => $sanitizedSubject,
                                ':u_from_name' => $sanitizedFromName,
                                ':u_date_str'  => $date,
                                ':u_body'      => $sanitizedBody,
                                ':u_snippet'   => $sanitizedSnippet,
                                ':u_is_seen'   => $isSeen ? 1 : 0
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
                                $msg->setFlag(['Seen']);
                            } catch (\Throwable $errFlag) {
                                \App\Logger::warning('setFlag failed for ' . $account['email_address'] . ' uid=' . $uid . ': ' . $errFlag->getMessage());
                                try {
                                    $msg->markAsRead();
                                } catch (\Throwable $errRead) {
                                    \App\Logger::warning('markAsRead also failed for ' . $account['email_address'] . ' uid=' . $uid . ': ' . $errRead->getMessage());
                                }
                            }
                        }
                    }
                }

                try {
                    if ($client) {
                        $client->disconnect();
                    }
                } catch (\Throwable $_) {}

            } catch (\Throwable $e) {
                try {
                    if ($client) {
                        $client->disconnect();
                    }
                } catch (\Throwable $_) {}

                $message = $e->getMessage();
                if (stripos($message, 'auth') !== false || stripos($message, 'login') !== false || stripos($message, 'password') !== false || stripos($message, 'authenticate') !== false) {
                    $errorType = 'AUTH_FAILED';
                } elseif (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                    $errorType = 'CONNECTION_TIMEOUT';
                } else {
                    $errorType = 'IMAP_ERROR';
                }

                $allEmails[] = [
                    'account_id'    => $account['id'],
                    'account_label' => $account['label'],
                    'account_email' => $account['email_address'],
                    'error_type'    => $errorType,
                    'error'         => $message
                ];
            }
        }

        return $allEmails;
    }

    /**
     * Connect with a short retry for transient IMAP server errors. Yahoo (and
     * some other providers) return "NO [UNAVAILABLE] LOGIN ... try again later"
     * when the mailbox is briefly overloaded or rate-limiting; a retry a moment
     * later usually succeeds. Genuine auth failures are not retried.
     */
    private function connectWithRetry($client): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $client->connect();
                return;
            } catch (\Throwable $e) {
                $isLast = ($attempt === 2);
                if (!$isLast && $this->isTransientImapError($e->getMessage())) {
                    usleep(500000 * ($attempt + 1)); // 0.5s, then 1s backoff
                    continue;
                }
                throw $e;
            }
        }
    }

    private function isTransientImapError(string $message): bool
    {
        $m = strtolower($message);
        foreach ([
            'try again later',
            'unavailable',
            'temporarily',
            'timed out',
            'timeout',
            'connection reset',
            'broken pipe',
            'too many connections',
            'rate limit',
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }
}