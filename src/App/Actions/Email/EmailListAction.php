<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;
use Webklex\PHPIMAP\ClientManager;
use DateTime;

class EmailListAction extends BaseAction
{
    public function __construct(private Database $db)
    {
    }

    public function execute(): void
    {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $limit = 12;
        $offset = ($page - 1) * $limit;

        $useCache = false;
        $list = [];
        $total = 0;

        if ($page > 1) {
            $cachedCount = $this->db->query("SELECT COUNT(*) as count FROM email_cache WHERE account_id = :account_id", [
                ':account_id' => $accountId
            ]);
            $total = (int)($cachedCount[0]['count'] ?? 0);

            if ($total > $offset) {
                try {
                    $list = $this->db->query("
                        SELECT uid, from_name as `from`, subject, date_str as date, is_seen 
                        FROM email_cache 
                        WHERE account_id = :account_id 
                        ORDER BY fetched_at DESC 
                        LIMIT :limit OFFSET :offset
                    ", [
                        ':account_id' => $accountId,
                        ':limit'      => $limit,
                        ':offset'     => $offset
                    ]);

                    if (count($list) === $limit || ($offset + count($list)) === $total) {
                        $useCache = true;
                    }
                } catch (\Throwable $errCache) {
                    $useCache = false;
                }
            }
        }

        if ($useCache) {
            $this->jsonResponse([
                'status'       => 'success',
                'emails'       => $list,
                'page'         => $page,
                'total_pages'  => (int)ceil($total / $limit),
                'total_emails' => $total
            ]);
            return;
        }

        $accountList = $this->db->selectSafe('email_accounts', ['id' => $accountId]);
        if (empty($accountList)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Account not found.'], 404);
            return;
        }

        $account = $accountList[0];
        $client = null;

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $account['imap_host'],
                'port'          => $account['imap_port'] ?: 993,
                'encryption'    => 'ssl',
                'validate_cert' => true,
                'username'      => $account['email_address'],
                'password'      => $account['app_password'],
                'protocol'      => 'imap',
                'timeout'       => 5,
                'options'       => [
                    'timeout'     => 5,
                    'fetch_order' => 'desc'
                ]
            ]);

            $client->connect();
            
            // Official Multi-tier Inbox Folder Lookup
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

            if (!$inbox) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Inbox folder unreachable.'], 500);
                return;
            }

            // Use cache count as primary (instant, no IMAP round-trip).
            // Only fall back to IMAP SEARCH count on cold caches.
            $cacheTotalRow = $this->db->query(
                "SELECT COUNT(*) as count FROM email_cache WHERE account_id = :account_id",
                [':account_id' => $accountId]
            );
            $total = (int)($cacheTotalRow[0]['count'] ?? 0);

            if ($total === 0) {
                try {
                    $total = (int)$inbox->query()->since(new DateTime("-7 days"))->count();
                } catch (\Throwable $_countErr) {
                    error_log('IMAP count failed for account ' . $accountId . ': ' . $_countErr->getMessage());
                    $total = 0;
                }
            }

            // Safeguard: Bypassing out-of-bounds fetches to prevent webklex empty fetch crash (Issue #520)
            if ($total === 0 || $offset >= $total) {
                try {
                    $client->disconnect();
                } catch (\Throwable $_) {}

                $this->jsonResponse([
                    'status'       => 'success',
                    'emails'       => [],
                    'page'         => $page,
                    'total_pages'  => (int)ceil($total / $limit),
                    'total_emails' => $total
                ]);
                return;
            }

            // Fetch messages for the requested page — strictly bounded to the 7-day date window
            try {
                $sinceDate = new DateTime("-7 days");
                $queryBuilder = $inbox->query()->since($sinceDate);
                try {
                    $queryBuilder->setFetchOrder('desc');
                } catch (\Throwable $_sortErr) {}
                $queryBuilder->setFetchBody(false);
                $queryBuilder->leaveUnread();
                
                // Note: limit() accepts page number directly, NOT fetchOffset
                $messages = $queryBuilder->limit($limit, $page)->get();
            } catch (\Throwable $_fetchErr) {
                error_log('IMAP fetch failed for account ' . $accountId . ', page ' . $page . ': ' . $_fetchErr->getMessage() . ' — class: ' . get_class($_fetchErr));
                throw new \RuntimeException("IMAP fetch failed (account_id=$accountId, page=$page). Error: " . $_fetchErr->getMessage());
            }

            $list = [];
            foreach ($messages as $msg) {
                $subject = (string)$msg->getSubject();
                if (!empty($subject)) {
                    $decodedSubject = @mb_decode_mimeheader($subject);
                    if ($decodedSubject !== false) {
                        $subject = $decodedSubject;
                    }
                }

                $fromObj = $msg->getFrom()->first();
                $fromName = '';
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
                    $fromName = 'Unknown Sender';
                }

                $fromName = trim($fromName, '"\'');

                if (!empty($fromName)) {
                    $decodedFrom = @mb_decode_mimeheader($fromName);
                    if ($decodedFrom !== false) {
                        $fromName = $decodedFrom;
                    }
                }

                $uid = (string)$msg->getUid();
                $dateStr = (string)$msg->getDate();

                // Correctly define $isSeen based on the message flags
                $isSeen = false;
                try {
                    $isSeen = $msg->hasFlag('seen') || $msg->getFlags()->has('seen');
                } catch (\Throwable $_) {
                    $isSeen = false;
                }

                $sanitizedSubject = (string)mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
                $sanitizedFromName = (string)mb_convert_encoding($fromName, 'UTF-8', 'UTF-8');

                // Obtain standard timestamp safely via official Attribute::toDate() method
                $timestamp = 0;
                try {
                    $timestamp = $msg->getDate()->toDate()->getTimestamp();
                } catch (\Throwable $_) {
                    try {
                        $timestamp = strtotime((string)$msg->getDate()) ?: 0;
                    } catch (\Throwable $_) {}
                }

                try {
                    $this->db->query("INSERT INTO email_cache (account_id, uid, subject, from_name, date_str, is_seen) 
                            VALUES (:account_id, :uid, :subject, :from_name, :date_str, :is_seen)
                            ON DUPLICATE KEY UPDATE 
                            subject = :u_subject, from_name = :u_from_name, date_str = :u_date_str, is_seen = :u_is_seen", [
                        ':account_id'  => (int)$accountId,
                        ':uid'         => $uid,
                        ':subject'     => $sanitizedSubject,
                        ':from_name'   => $sanitizedFromName,
                        ':date_str'    => $dateStr,
                        ':is_seen'     => $isSeen ? 1 : 0,
                        ':u_subject'   => $sanitizedSubject,
                        ':u_from_name' => $sanitizedFromName,
                        ':u_date_str'  => $dateStr,
                        ':u_is_seen'   => $isSeen ? 1 : 0
                    ]);
                } catch (\Throwable $_cacheWriteErr) {
                    error_log('Email cache write failed for account ' . $accountId . ', uid ' . $uid . ': ' . $_cacheWriteErr->getMessage());
                }

                $list[] = [
                    'uid'       => $uid,
                    'from'      => $fromName,
                    'subject'   => $subject,
                    'date'      => $dateStr,
                    'is_seen'   => $isSeen,
                    'timestamp' => $timestamp
                ];
            }

            // Local sorting to guarantee reverse-chronological order
            usort($list, function ($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });

            // Clean up temporary timestamp field
            foreach ($list as &$item) {
                unset($item['timestamp']);
            }
            unset($item);

            // Cleanly disconnect socket manually to avoid uncaught __destruct empty response exceptions
            try {
                if ($client) {
                    $client->disconnect();
                }
            } catch (\Throwable $_) {}

            $this->jsonResponse([
                'status'       => 'success',
                'emails'       => $list,
                'page'         => $page,
                'total_pages'  => (int)ceil($total / $limit),
                'total_emails' => $total
            ]);
        } catch (\Throwable $e) {
            // Suppress destructor issues on GC if something crashed earlier
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

            $this->jsonResponse([
                'status' => 'error',
                'type'   => $errorType,
                'message' => $e->getMessage(),
                'account_id' => (string)$accountId,
                'account_email' => $account['email_address'] ?? ''
            ], 500);
        }
    }
}