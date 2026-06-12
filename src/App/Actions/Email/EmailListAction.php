<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;
use Webklex\PHPIMAP\ClientManager;

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
            try {
                $cachedCount = $this->db->query("SELECT COUNT(*) as count FROM email_cache WHERE account_id = :account_id", [
                    ':account_id' => $accountId
                ]);
                $total = (int)($cachedCount[0]['count'] ?? 0);

                if ($total > $offset) {
                    $list = $this->db->query("
                        SELECT uid, from_name as `from`, subject, date_str as date, is_seen 
                        FROM email_cache 
                        WHERE account_id = :account_id 
                        ORDER BY date_str DESC 
                        LIMIT :limit OFFSET :offset
                    ", [
                        ':account_id' => $accountId,
                        ':limit'      => $limit,
                        ':offset'     => $offset
                    ]);

                    if (count($list) === $limit || ($offset + count($list)) === $total) {
                        $useCache = true;
                    }
                }
            } catch (\Throwable $errCache) {
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
            $inbox = $client->getFolder('INBOX');
            if (!$inbox) {
                $folders = $client->getFolders();
                $inbox = $folders[0] ?? null;
            }

            if (!$inbox) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Inbox folder unreachable.'], 500);
                return;
            }

            $info = $inbox->examine();
            $total = (int)($info['exists'] ?? 0);
            $totalPages = (int)ceil($total / $limit);

            $list = [];
            if ($total > 0) {
                $fetchPage = $totalPages - $page + 1;
                if ($fetchPage < 1) {
                    $fetchPage = 1;
                }

                $messages = $inbox->query()->all()
                    ->limit($limit, $fetchPage)
                    ->get();

                if ($page === 1 && count($messages) < $limit && $totalPages > 1) {
                    try {
                        $prevMessages = $inbox->query()->all()
                            ->limit($limit, $totalPages - 1)
                            ->get();

                        $merged = [];
                        foreach ($prevMessages as $m) {
                            $merged[] = $m;
                        }
                        foreach ($messages as $m) {
                            $merged[] = $m;
                        }

                        if (count($merged) > $limit) {
                            $merged = array_slice($merged, -$limit);
                        }
                        $messages = $merged;
                    } catch (\Throwable $e) {
                    }
                }

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
                    $isSeen = (bool)$msg->getFlags()->has('seen');

                    $sanitizedSubject = mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
                    $sanitizedFromName = mb_convert_encoding($fromName, 'UTF-8', 'UTF-8');

                    try {
                        $this->db->query("INSERT INTO email_cache (account_id, uid, subject, from_name, date_str, is_seen) 
                            VALUES (:account_id, :uid, :subject, :from_name, :date_str, :is_seen)
                            ON DUPLICATE KEY UPDATE 
                            subject = :u_subject, from_name = :u_from_name, date_str = :u_date_str, is_seen = :u_is_seen", [
                            ':account_id'  => $accountId,
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
                    } catch (\Throwable $errCacheWrite) {
                    }

                    $list[] = [
                        'uid'      => $uid,
                        'from'     => $fromName,
                        'subject'  => $subject,
                        'date'     => $dateStr,
                        'is_seen'  => $isSeen
                    ];
                }
                $list = array_reverse($list);
            }

            $this->jsonResponse([
                'status'       => 'success',
                'emails'       => $list,
                'page'         => $page,
                'total_pages'  => $totalPages,
                'total_emails' => $total
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
