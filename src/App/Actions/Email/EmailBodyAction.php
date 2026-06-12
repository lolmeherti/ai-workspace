<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;
use Webklex\PHPIMAP\ClientManager;

class EmailBodyAction extends BaseAction
{
    public function __construct(private Database $db)
    {
    }

    public function execute(): void
    {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $uid = (string)($_GET['uid'] ?? '');

        $replies = [];
        try {
            $replies = $this->db->query("SELECT * FROM email_replies WHERE account_id = :account_id AND parent_uid = :parent_uid ORDER BY created_at ASC", [
                ':account_id' => $accountId,
                ':parent_uid' => $uid
            ]);
        } catch (\Throwable $errReplies) {
        }

        try {
            $cached = $this->db->query("SELECT * FROM email_cache WHERE account_id = :account_id AND uid = :uid LIMIT 1", [
                ':account_id' => $accountId,
                ':uid'        => $uid
            ]);

            if (!empty($cached) && !empty($cached[0]['body'])) {
                $email = $cached[0];
                $this->jsonResponse([
                    'status'  => 'success',
                    'body'    => $email['body'],
                    'from'    => $email['from_name'],
                    'subject' => $email['subject'],
                    'date'    => $email['date_str'],
                    'replies' => $replies
                ]);
                return;
            }
        } catch (\Throwable $errCacheRead) {
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
                'options'       => ['timeout' => 5]
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

            $msg = null;
            try {
                $msg = $inbox->getMessageByUid((int)$uid);
            } catch (\Throwable $errDirect) {
                try {
                    $msg = $inbox->query()->all()->whereUid((int)$uid)->get()->first();
                } catch (\Throwable $errQuery) {
                }
            }

            if (!$msg) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Message not found.'], 404);
                return;
            }

            $html = (string)$msg->getHTMLBody();
            if (empty($html)) {
                $html = nl2br(htmlspecialchars((string)$msg->getTextBody()));
            }

            try {
                $msg->setFlag('Seen');
            } catch (\Throwable $t) {
            }

            $subject = (string)$msg->getSubject();
            if (!empty($subject)) {
                $decodedSubject = @mb_decode_mimeheader($subject);
                if ($decodedSubject !== false) {
                    $subject = $decodedSubject;
                }
            }

            $fromName = '';
            try {
                $fromObj = $msg->getFrom();
                if ($fromObj) {
                    $firstFrom = method_exists($fromObj, 'first') ? $fromObj->first() : (is_array($fromObj) ? ($fromObj[0] ?? null) : $fromObj);
                    if ($firstFrom) {
                        $personal = isset($firstFrom->personal) ? (string)$firstFrom->personal : '';
                        $mail = isset($firstFrom->mail) ? (string)$firstFrom->mail : '';
                        if (!empty($personal)) {
                            $decodedFrom = @mb_decode_mimeheader($personal);
                            if ($decodedFrom !== false) {
                                $fromName = $decodedFrom;
                            } else {
                                $fromName = $personal;
                            }
                        } else {
                            $fromName = $mail;
                        }
                        $fromName = trim($fromName, '"\'');
                        if (!empty($mail)) {
                            $fromName .= ' <' . $mail . '>';
                        }
                    }
                }
            } catch (\Throwable $errFrom) {
            }

            if (empty($fromName)) {
                try {
                    $fallbackFrom = $msg->getFrom()->first();
                    $fromName = $fallbackFrom ? ((string)$fallbackFrom->personal ?: (string)$fallbackFrom->mail) : 'Unknown Sender';
                } catch (\Throwable $errFallback) {
                    $fromName = 'Unknown Sender';
                }
            }

            $dateStr = (string)$msg->getDate();

            $sanitizedSubject = mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
            $sanitizedFromName = mb_convert_encoding($fromName, 'UTF-8', 'UTF-8');
            $sanitizedBody = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
            $sanitizedSnippet = mb_convert_encoding(strip_tags($html), 'UTF-8', 'UTF-8');

            try {
                $this->db->query("INSERT INTO email_cache (account_id, uid, subject, from_name, date_str, body, snippet) 
                    VALUES (:account_id, :uid, :subject, :from_name, :date_str, :body, :snippet)
                    ON DUPLICATE KEY UPDATE 
                    subject = :u_subject, from_name = :u_from_name, date_str = :u_date_str, body = :u_body, snippet = :u_snippet", [
                    ':account_id'  => $accountId,
                    ':uid'         => $uid,
                    ':subject'     => $sanitizedSubject,
                    ':from_name'   => $sanitizedFromName,
                    ':date_str'    => $dateStr,
                    ':body'        => $sanitizedBody,
                    ':snippet'     => $sanitizedSnippet,
                    ':u_subject'   => $sanitizedSubject,
                    ':u_from_name' => $sanitizedFromName,
                    ':u_date_str'  => $dateStr,
                    ':u_body'      => $sanitizedBody,
                    ':u_snippet'   => $sanitizedSnippet
                ]);
            } catch (\Throwable $errCacheWrite) {
            }

            $this->jsonResponse([
                'status'  => 'success',
                'body'    => $html,
                'from'    => $fromName,
                'subject' => $subject,
                'date'    => $dateStr,
                'replies' => $replies
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
