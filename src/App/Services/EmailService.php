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
                $onAccountFetch([
                    'phase'    => 'start',
                    'email'    => $account['email_address'],
                    'label'    => $account['label'],
                    'provider' => $account['provider'] ?? 'Email',
                ]);
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
                    'timeout'       => 30,
                    'options'       => [
                        'timeout' => 30
                    ]
                ]);

                $authStart = microtime(true);
                self::connectWithRetry($client);
                $authMs = (int) round((microtime(true) - $authStart) * 1000);

                if ($onAccountFetch !== null) {
                    $onAccountFetch([
                        'phase'    => 'authed',
                        'email'    => $account['email_address'],
                        'label'    => $account['label'],
                        'provider' => $account['provider'] ?? 'Email',
                        'auth_ms'  => $authMs,
                    ]);
                }

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

                if ($onAccountFetch !== null) {
                    $onAccountFetch([
                        'phase'    => 'fetched',
                        'email'    => $account['email_address'],
                        'label'    => $account['label'],
                        'provider' => $account['provider'] ?? 'Email',
                        'count'    => count($messagesArray),
                    ]);
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

                $classified = self::classifyImapError($e);

                $allEmails[] = [
                    'account_id'    => $account['id'],
                    'account_label' => $account['label'],
                    'account_email' => $account['email_address'],
                    'error_type'    => $classified['type'],
                    'error'         => $classified['detail'],
                ];
            }
        }

        return $allEmails;
    }

    /**
     * Connect with a short retry for transient IMAP failures. The retry gate
     * uses classifyImapError(), which walks the full previous-exception chain,
     * so a socket read timeout ("empty response") or a dropped connection is
     * retried while a genuine credential rejection ("Invalid credentials") is
     * not (retrying those can trigger Gmail's lockout).
     */
    public static function connectWithRetry($client): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $client->connect();
                return;
            } catch (\Throwable $e) {
                $isLast = ($attempt === 2);
                if (!$isLast && self::classifyImapError($e)['transient']) {
                    usleep(500000 * ($attempt + 1)); // 0.5s, then 1s backoff
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Classify an IMAP exception by walking the full previous-exception chain.
     *
     * webklex/php-imap hides the real cause behind generic wrapper messages:
     * a socket read timeout surfaces as RuntimeException("empty response"),
     * which login() relabels AuthFailedException("failed to authenticate"), and
     * a connect failure becomes ConnectionFailedException("connection setup
     * failed"). Checking only getMessage() therefore mislabels a transient
     * timeout as AUTH_FAILED. We walk getPrevious() to the root cause.
     *
     * @return array{type:string, detail:string, transient:bool}
     */
    public static function classifyImapError(\Throwable $e): array
    {
        $messages = self::exceptionChainMessages($e); // most-recent first
        $full = strtolower(implode(' | ', $messages));
        $rootCause = $messages === [] ? $e->getMessage() : $messages[count($messages) - 1];
        $rootLower = strtolower($rootCause);

        $transient = false;
        foreach ([
            'try again later', 'unavailable', 'temporarily', 'timed out', 'timeout',
            'connection reset', 'broken pipe', 'too many connections', 'rate limit',
            'empty response', 'connection closed', 'failed to write', 'eof',
        ] as $needle) {
            if (str_contains($full, $needle)) {
                $transient = true;
                break;
            }
        }

        $auth = false;
        foreach ([
            'authenticationfailed', 'invalid credentials', 'bad credentials',
            'incorrect password', 'wrong password', 'auth failed',
            'authenticate failed', 'login failed', 'login incorrect', 'bad login',
        ] as $needle) {
            if (str_contains($full, $needle)) {
                $auth = true;
                break;
            }
        }

        // A genuine credential rejection is never transient, even if a
        // connection-level needle also appears elsewhere in the chain.
        $transient = $transient && !$auth;

        if ($auth) {
            $type = 'AUTH_FAILED';
        } elseif (str_contains($full, 'timeout') || str_contains($full, 'timed out')) {
            $type = 'CONNECTION_TIMEOUT';
        } else {
            $type = 'IMAP_ERROR';
        }

        $friendly = [
            'empty response'                    => 'mail server dropped the connection during login',
            'failed to write - connection closed?' => 'connection closed during login',
            'failed to send literal string'     => 'connection interrupted during login',
        ];
        $detail = $friendly[$rootLower] ?? $rootCause;

        return ['type' => $type, 'detail' => $detail, 'transient' => $transient];
    }

    private static function exceptionChainMessages(\Throwable $e): array
    {
        $messages = [];
        $cur = $e;
        $depth = 0;
        while ($cur !== null && $depth < 10) {
            $m = $cur->getMessage();
            if ($m !== '') {
                $messages[] = $m;
            }
            $cur = $cur->getPrevious();
            $depth++;
        }
        return $messages;
    }
}