<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;
use Webklex\PHPIMAP\ClientManager;

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

        $fail = function (string $message, string $type = 'IMAP_ERROR', string $detail = ''): void {
            if ($this->isApiRequest()) {
                $this->jsonResponse([
                    'status'  => 'error',
                    'type'    => $type,
                    'message' => $message,
                    'detail'  => $detail,
                ], 400);
            }
            $this->redirect('index.php?tab=emails&email_error=' . urlencode($message));
        };

        if (empty($label) || empty($provider) || empty($emailAddress) || empty($appPassword)) {
            $fail('Label, provider, email address and app password are required.');
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
        } elseif ($provider === 'Custom IMAP') {
            if (empty($imapHost) || empty($imapPort)) {
                $fail('Custom IMAP requires both an IMAP host and port.');
            }
        }

        // Verify the credentials actually authenticate BEFORE saving anything.
        // This is the honest gate: no row, no "blue dot", unless the login works.
        $failure = $this->testConnection($emailAddress, $appPassword, $imapHost, $imapPort);
        if ($failure !== null) {
            $fail($failure['message'], $failure['type'], $failure['detail']);
        }

        $this->db->insert('email_accounts', [
            'label'         => $label,
            'provider'      => $provider,
            'email_address' => $emailAddress,
            'app_password'  => $appPassword,
            'imap_host'     => $imapHost,
            'imap_port'     => $imapPort
        ]);

        if ($this->isApiRequest()) {
            $this->jsonResponse(['status' => 'success', 'message' => 'Account connected.']);
        }
        $this->redirect('index.php?tab=emails');
    }

    /**
     * Attempt a real IMAP login to verify the credentials.
     * Returns null on success, or ['type' => ..., 'message' => ..., 'detail' => ...] on failure.
     */
    private function testConnection(string $email, string $password, string $host, ?int $port): ?array
    {
        $cm = new ClientManager();
        $client = null;
        try {
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port ?: 993,
                'encryption'    => 'ssl',
                'validate_cert' => true,
                'username'      => $email,
                'password'      => $password,
                'protocol'      => 'imap',
                'timeout'       => 10,
                'options'       => ['timeout' => 10]
            ]);
            $client->connect();
            try {
                $client->disconnect();
            } catch (\Throwable $_) {}
            return null;
        } catch (\Throwable $e) {
            try {
                if ($client) {
                    $client->disconnect();
                }
            } catch (\Throwable $_) {}

            $message = $e->getMessage();
            if (stripos($message, 'auth') !== false || stripos($message, 'login') !== false
                || stripos($message, 'password') !== false || stripos($message, 'authenticate') !== false
                || stripos($message, 'empty response') !== false) {
                $type = 'AUTH_FAILED';
                $friendly = 'Authentication failed — check your email address and app password.';
            } elseif (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                $type = 'CONNECTION_TIMEOUT';
                $friendly = 'Connection timed out — check the IMAP host and port.';
            } else {
                $type = 'IMAP_ERROR';
                $friendly = 'Could not connect to the mail server.';
            }
            return ['type' => $type, 'message' => $friendly, 'detail' => $message];
        }
    }
}
