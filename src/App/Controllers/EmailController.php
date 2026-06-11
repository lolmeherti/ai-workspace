<?php

namespace App\Controllers;

use App\Database;
use App\Enums\Action;
use App\Enums\ApiAction;
use Webklex\PHPIMAP\ClientManager;
use PHPMailer\PHPMailer\PHPMailer;

class EmailController extends BaseController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $actionVal = $_POST['action'] ?? '';
            $action = Action::tryFrom($actionVal);

            if ($action === Action::ADD_EMAIL_ACCOUNT) {
                $this->addAccount();
            } elseif ($action === Action::DELETE_EMAIL_ACCOUNT) {
                $this->deleteAccount();
            } elseif ($action === Action::SEND_REPLY) {
                $this->sendReply();
            }
        } else {
            $apiActionVal = $_GET['api_action'] ?? '';
            $apiAction = ApiAction::tryFrom($apiActionVal);

            if ($apiAction === ApiAction::GET_EMAILS) {
                $this->getEmails();
            } elseif ($apiAction === ApiAction::GET_EMAIL_BODY) {
                $this->getEmailBody();
            }
        }
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

    private function addAccount(): void
    {
        $label = trim($_POST['label'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $emailAddress = trim($_POST['email_address'] ?? '');
        $appPassword = $_POST['app_password'] ?? '';
        $imapHost = trim($_POST['imap_host'] ?? '');
        $imapPort = isset($_POST['imap_port']) && $_POST['imap_port'] !== '' ? (int)$_POST['imap_port'] : null;

        if (empty($label) || empty($provider) || empty($emailAddress) || empty($appPassword)) {
            $this->redirect("index.php?tab=emails");
            return;
        }

        if ($provider === 'Gmail') {
            $imapHost = 'imap.gmail.com';
            $imapPort = 993;
        } elseif ($provider === 'Yandex') {
            $imapHost = 'imap.yandex.com';
            $imapPort = 993;
        } elseif ($provider === 'Yahoo') {
            $imapHost = 'imap.mail.yahoo.com';
            $imapPort = 993;
        }

        $this->db->insert('email_accounts', [
            'label'         => $label,
            'provider'      => $provider,
            'email_address' => $emailAddress,
            'app_password'  => $appPassword,
            'imap_host'     => $imapHost,
            'imap_port'     => $imapPort
        ]);

        $this->redirect("index.php?tab=emails");
    }

    private function deleteAccount(): void
    {
        $id = (int)($_POST['account_id'] ?? 0);
        if ($id > 0) {
            $this->db->query("DELETE FROM email_accounts WHERE id = :id", [':id' => $id]);
        }
        $this->redirect("index.php?tab=emails");
    }

    private function getEmails(): void
    {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $this->ensureCacheTableExists();

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

    private function getEmailBody(): void
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

    private function sendReply(): void
    {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $to = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $parentUid = trim($_POST['parent_uid'] ?? '');

        if (empty($to) || empty($subject) || empty($body)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Required fields missing.'], 400);
            return;
        }

        $accountList = $this->db->selectSafe('email_accounts', ['id' => $accountId]);
        if (empty($accountList)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Account not found.'], 404);
            return;
        }

        $account = $accountList[0];

        $smtpHost = '';
        if ($account['provider'] === 'Gmail') {
            $smtpHost = 'smtp.gmail.com';
        } elseif ($account['provider'] === 'Yandex') {
            $smtpHost = 'smtp.yandex.com';
        } elseif ($account['provider'] === 'Yahoo') {
            $smtpHost = 'smtp.mail.yahoo.com';
        } else {
            $smtpHost = str_replace('imap.', 'smtp.', $account['imap_host']);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $account['email_address'];
            $mail->Password   = $account['app_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($account['email_address'], $account['label']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body    = $body;

            $mail->send();

            if (!empty($parentUid)) {
                try {
                    $this->db->query("CREATE TABLE IF NOT EXISTS email_replies (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        account_id INT NOT NULL,
                        parent_uid VARCHAR(255) NOT NULL,
                        recipient VARCHAR(255) NOT NULL,
                        subject VARCHAR(255) NOT NULL,
                        body LONGTEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                    $this->db->insert('email_replies', [
                        'account_id' => $accountId,
                        'parent_uid' => $parentUid,
                        'recipient'  => mb_convert_encoding($to, 'UTF-8', 'UTF-8'),
                        'subject'    => mb_convert_encoding($subject, 'UTF-8', 'UTF-8'),
                        'body'       => mb_convert_encoding($body, 'UTF-8', 'UTF-8')
                    ]);
                } catch (\Throwable $e) {
                }
            }

            $this->jsonResponse(['status' => 'success', 'message' => 'Email sent successfully.']);
        } catch (\Throwable $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}