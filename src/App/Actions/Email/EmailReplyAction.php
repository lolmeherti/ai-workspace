<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;
use PHPMailer\PHPMailer\PHPMailer;

class EmailReplyAction extends BaseAction
{
    public function __construct(private Database $db)
    {
    }

    public function execute(): void
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
