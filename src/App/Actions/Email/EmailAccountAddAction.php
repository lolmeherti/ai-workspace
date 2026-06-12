<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;

class EmailAccountAddAction extends BaseAction
{
    public function __construct(private Database $db)
    {
    }

    public function execute(): void
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
}
