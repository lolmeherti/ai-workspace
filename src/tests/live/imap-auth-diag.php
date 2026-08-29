<?php

declare(strict_types=1);

/*
 * IMAP auth failure diagnostic — LIVE (real Gmail account, real credentials
 * from the email_accounts table, real network).
 *
 * Goal: uncover the REAL reason behind the recurring
 *   "failed to authenticate"
 * message. webklex/php-imap wraps a low-level RuntimeException (timeout,
 * "empty response", "failed to write", etc.) as AuthFailedException("failed
 * to authenticate") — hiding the actual cause in the exception->getPrevious()
 * chain. This script walks that chain and prints every link's class + message
 * WITHOUT ever echoing the email address or app password.
 *
 * Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/imap-auth-diag.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use Webklex\PHPIMAP\ClientManager;

Config::load(dirname(__DIR__, 2));

// Mask an email address so only the first char of the local part + domain show.
function mask(string $email): string
{
    if (!str_contains($email, '@')) {
        return '(no email)';
    }
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . '***@' . $domain;
}

// Walk an exception chain to its deepest cause and collect every link.
function chain(Throwable $e): array
{
    $links = [];
    $cur = $e;
    $depth = 0;
    while ($cur !== null && $depth < 8) {
        $links[] = [
            'class'   => get_class($cur),
            'message' => $cur->getMessage(),
            'code'    => $cur->getCode(),
        ];
        $cur = $cur->getPrevious();
        $depth++;
    }
    return $links;
}

// --- Database ---
try {
    $db = new Database();
    echo "DB: connected\n";
} catch (Throwable $dbErr) {
    echo "DB: CONNECT FAILED — " . $dbErr->getMessage() . "\n";
    exit(1);
}

$rows = $db->query('SELECT id, provider, email_address, imap_host, imap_port FROM email_accounts');
if (empty($rows)) {
    echo "DB: no email_accounts rows\n";
    exit(1);
}

echo 'DB: ' . count($rows) . " account(s)\n";
foreach ($rows as $a) {
    echo "  id={$a['id']} provider={$a['provider']} email=" . mask($a['email_address'])
        . " host={$a['imap_host']}:{$a['imap_port']}\n";
}

// For each account, read the FULL row (incl. app_password) but never print it.
$accounts = $db->query('SELECT * FROM email_accounts');

$attemptsPerAccount = 6;
$sleepSeconds = 2;

foreach ($accounts as $account) {
    $email = $account['email_address'] ?? '';
    $label = $account['label'] ?? '(no label)';
    echo "\n=== " . mask($email) . " ({$label}) — $attemptsPerAccount attempts ===\n";

    $ok = 0;
    $fail = 0;

    for ($i = 1; $i <= $attemptsPerAccount; $i++) {
        $cm = new ClientManager();
        $client = null;
        $t0 = microtime(true);
        try {
            $client = $cm->make([
                'host'          => $account['imap_host'],
                'port'          => $account['imap_port'] ?: 993,
                'encryption'    => 'ssl',
                'validate_cert' => true,
                'username'      => $email,
                'password'      => $account['app_password'],
                'protocol'      => 'imap',
                'timeout'       => 5,
                'options'       => ['timeout' => 5],
            ]);
            $client->connect();
            $ms = (int) round((microtime(true) - $t0) * 1000);
            echo "  #$i OK ({$ms}ms)\n";
            $ok++;
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $fail++;
            echo "  #$i FAIL ({$ms}ms):\n";
            foreach (chain($e) as $link) {
                echo "      {$link['class']}: {$link['message']}\n";
            }
        } finally {
            try {
                if ($client) {
                    $client->disconnect();
                }
            } catch (Throwable $_) {
            }
        }

        if ($i < $attemptsPerAccount) {
            sleep($sleepSeconds);
        }
    }

    echo "  RESULT: ok=$ok fail=$fail\n";
}

echo "\nDone.\n";
