<?php

declare(strict_types=1);

/*
 * IMAP latency diagnostic — granular phase timing (LIVE, real accounts).
 *
 * Splits each connection into three phases to pinpoint WHERE the time goes:
 *   1. connect — TCP + TLS handshake (stream_socket_client returns)
 *   2. greet   — reading the server's * OK banner
 *   3. LOGIN   — the AUTHENTICATE/LOGIN exchange (this is Google's auth)
 *
 * If LOGIN is the slow phase and it varies per account while connect/greet
 * stay flat, the latency is Google's per-account authentication processing,
 * not the network path. Email + app password are read but NEVER printed.
 *
 * Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/imap-auth-timing.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

function mask(string $email): string
{
    if (!str_contains($email, '@')) {
        return '(no email)';
    }
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . '***@' . $domain;
}

function redact(string $line, string $email, string $password): string
{
    return str_replace([$email, $password], ['***EMAIL***', '***PASS***'], $line);
}

$db = new Database();
$accounts = $db->query('SELECT * FROM email_accounts WHERE provider = "Gmail"');

if (empty($accounts)) {
    echo "No Gmail accounts found.\n";
    exit(1);
}

$attempts = 6;

foreach ($accounts as $account) {
    $email = $account['email_address'];
    $pass  = $account['app_password'];

    echo "\n=== " . mask($email) . " — $attempts attempts (connect | greet | LOGIN) ===\n";

    for ($i = 1; $i <= $attempts; $i++) {
        $t0 = microtime(true);
        $stream = @stream_socket_client('ssl://imap.gmail.com:993', $errno, $errstr, 15);
        $tConnect = microtime(true);

        if (!$stream) {
            echo "  #$i CONNECT FAIL: $errstr ($errno)\n";
            continue;
        }

        stream_set_timeout($stream, 15);

        $greeting = fgets($stream);
        $tGreeting = microtime(true);

        fwrite($stream, 'A1 LOGIN ' . json_encode($email) . ' ' . json_encode($pass) . "\r\n");
        $resp = fgets($stream);
        $tLogin = microtime(true);

        fwrite($stream, "A2 LOGOUT\r\n");
        fclose($stream);

        $connectMs = (int) round(($tConnect - $t0) * 1000);
        $greetMs   = (int) round(($tGreeting - $tConnect) * 1000);
        $loginMs   = (int) round(($tLogin - $tGreeting) * 1000);

        $respShort = substr(redact((string) $resp, $email, $pass), 0, 90);
        $respShort = trim((string) $respShort);

        echo sprintf("  #%d connect=%dms greet=%dms LOGIN=%dms  resp=%s\n", $i, $connectMs, $greetMs, $loginMs, $respShort);
    }
}

echo "\nDone.\n";
