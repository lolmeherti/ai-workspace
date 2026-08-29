<?php

namespace App\Services;

use App\Config;
use App\Database;

/**
 * Deterministic briefing preprocessing — zero model calls.
 *
 * Fetches the bounded inbox set, dedups, caps, cleans bodies, builds previews,
 * and computes the context budget. Also partitions calendar tasks into
 * upcoming (next 14 days) vs past-today. No importance heuristics: PHP only
 * enforces hard bounds and sanitation, never models what the user cares about.
 */
final class BriefingDataService
{
    public const MAX_EMAILS = 30;
    public const OUTPUT_RESERVE_TOKENS = 4096;
    public const SAFETY_TOKENS = 1024;
    public const PREVIEW_LEN = 120;

    public function __construct(
        private Database $db,
        private EmailService $emailService
    ) {
    }

    /**
     * Fetch, dedupe, cap, clean, and budget the bounded inbox set.
     *
     * @return array{emails: list<array>, errors: list<array>, omitted: int, budgetChars: int}
     */
    public function buildInput(bool $includeSeen, ?callable $onAccountFetch = null): array
    {
        $raw = $this->emailService->fetchRecentEmails($includeSeen, $onAccountFetch);

        [$emails, $errors, $omitted] = self::normalizeEmails($raw);

        $result = [];
        foreach ($emails as $email) {
            $body = $this->loadBody($email);
            $clean = self::cleanBody($body !== '' ? $body : ($email['snippet'] ?? ''));
            $email['body'] = $clean;
            $email['preview'] = self::buildPreview($email, $clean);
            $result[] = $email;
        }

        return [
            'emails'      => $result,
            'errors'      => $errors,
            'omitted'     => $omitted,
            'budgetChars' => self::computeBudgetChars(),
        ];
    }

    /**
     * Pure: dedupe by (account_id, uid), global newest-first sort, hard cap, and
     * assign stable integer ids (1..N). Returns [emails, errors, omitted].
     */
    public static function normalizeEmails(array $raw): array
    {
        $seen = [];
        $errors = [];
        foreach ($raw as $email) {
            if (isset($email['error'])) {
                $errors[] = $email;
                continue;
            }
            $key = $email['account_id'] . ':' . $email['uid'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = $email;
        }
        $emails = array_values($seen);

        // Global newest-first ordering (the fetch is only per-account sorted).
        usort($emails, fn ($a, $b) => self::emailTimestamp($b) <=> self::emailTimestamp($a));

        $omitted = max(0, count($emails) - self::MAX_EMAILS);
        $emails = array_slice($emails, 0, self::MAX_EMAILS);

        foreach ($emails as $i => $e) {
            $emails[$i]['id'] = $i + 1;
        }

        return [$emails, $errors, $omitted];
    }

    /**
     * Read the persisted full body for an email, falling back to '' so the
     * caller can degrade to the snippet.
     */
    private function loadBody(array $email): string
    {
        try {
            $rows = $this->db->query(
                'SELECT body FROM email_cache WHERE account_id = :aid AND uid = :uid LIMIT 1',
                [':aid' => $email['account_id'], ':uid' => $email['uid']]
            );
            if (!empty($rows) && !empty($rows[0]['body'])) {
                return (string) $rows[0]['body'];
            }
        } catch (\Throwable $_) {
            // fall through to snippet fallback
        }
        return '';
    }

    /**
     * A compact, single-line hint for triage: sender + subject + a short body lead.
     */
    public static function buildPreview(array $email, ?string $cleanBody = null): string
    {
        $clean = $cleanBody ?? self::cleanBody((string) ($email['snippet'] ?? ''));
        $lead = mb_substr($clean, 0, self::PREVIEW_LEN);
        if (mb_strlen($clean) > self::PREVIEW_LEN) {
            $lead = rtrim($lead) . '…';
        }
        $subject = $email['subject'] ?? '';
        $subject = $subject !== '' ? $subject : '(no subject)';
        $from = $email['from'] ?? 'Unknown';
        return "From: {$from} | Subject: {$subject} | {$lead}";
    }

    /**
     * Deterministic cleanup: strip tags, decode entities, drop quoted-thread
     * markers, and truncate at the signature delimiter. No ranking — this only
     * removes structural junk (HTML, quoted history, signatures, footers).
     */
    public static function cleanBody(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            $lines = [$text];
        }

        $out = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $out[] = '';
                continue;
            }
            // Signature delimiter (RFC 3676 "-- ") — drop everything after.
            if ($trim === '--') {
                break;
            }
            // Quoted-thread markers (EN + DE). Deterministic, structural only.
            if (preg_match('/^>+/', $trim)) {
                continue;
            }
            if (preg_match('/^(On\s.+wrote:|Am\s.+\sschrieb\s.+:|-----Ursprüngliche Nachricht-----|----- Original Message -----)$/i', $trim)) {
                continue;
            }
            $out[] = $line;
        }

        $text = implode("\n", $out);
        $text = preg_replace('/[ \t]+/', ' ', $text);       // collapse horizontal whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);     // collapse blank runs
        return trim((string) $text);
    }

    /**
     * Character budget for extraction evidence, reserving output + safety
     * headroom against the model context. chars ~= tokens * 4.
     */
    public static function computeBudgetChars(?int $ctxSize = null): int
    {
        $ctx = $ctxSize ?? (int) Config::get('LLM_CTX_SIZE', 16384);
        if ($ctx <= 0) {
            $ctx = 16384;
        }
        return max(0, ($ctx - self::OUTPUT_RESERVE_TOKENS - self::SAFETY_TOKENS)) * 4;
    }

    /**
     * True when the sum of cleaned body lengths fits inside the evidence
     * budget (headroom is already baked into $budgetChars).
     */
    public static function bodiesFitWithHeadroom(array $bodies, int $budgetChars): bool
    {
        $total = 0;
        foreach ($bodies as $body) {
            $total += mb_strlen((string) $body);
        }
        return $total <= $budgetChars;
    }

    /**
     * Split calendar tasks into upcoming (next 14 days) vs past-today.
     * Tasks without a due date are dropped (matches prior briefing behavior).
     *
     * @return array{upcoming: list<array>, pastToday: list<array>}
     */
    public static function partitionCalendar(array $tasks, ?int $now = null): array
    {
        $now = $now ?? time();
        $twoWeeks = 14 * 24 * 60 * 60;
        $todayStart = strtotime('today 00:00:00', $now);

        $upcoming = [];
        $pastToday = [];
        foreach ($tasks as $task) {
            $dueTime = self::taskDueTime($task);
            if ($dueTime === null) {
                continue;
            }
            if ($dueTime >= $now && ($dueTime - $now) <= $twoWeeks) {
                $upcoming[] = $task;
            } elseif ($dueTime < $now && $dueTime >= $todayStart) {
                $pastToday[] = $task;
            }
        }

        return ['upcoming' => $upcoming, 'pastToday' => $pastToday];
    }

    private static function taskDueTime(array $task): ?int
    {
        if (isset($task['due']['datetime'])) {
            $t = strtotime((string) $task['due']['datetime']);
            return $t !== false ? $t : null;
        }
        if (isset($task['due']['date'])) {
            $t = strtotime((string) $task['due']['date']);
            return $t !== false ? $t : null;
        }
        return null;
    }

    private static function emailTimestamp(array $email): int
    {
        $date = (string) ($email['date'] ?? '');
        if ($date === '' || $date === 'Unknown Date') {
            return 0;
        }
        $t = strtotime($date);
        return $t !== false ? $t : 0;
    }
}
