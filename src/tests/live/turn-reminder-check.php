<?php

declare(strict_types=1);

/*
 * turn_reminder presence check (read-only).
 *
 * Verifies the invariant behind known-issues 5b: a tool turn's repeated-request
 * reminder is persisted on the evidence row it followed, and is therefore
 * re-emitted in the same position on every later turn. A reminder that exists
 * only in the array of the turn that produced it makes the next prompt diverge
 * exactly there, which cost the engine the whole cached prefix behind it
 * (measured before the fix: 1336 of 16225 reused, 8%).
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/turn-reminder-check.php [session_id]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

$sessionId = (int) ($argv[1] ?? 0);
if ($sessionId <= 0) {
    fwrite(STDERR, "usage: turn-reminder-check.php <session_id>\n");
    exit(1);
}

$db = new Database();
$rows = $db->selectSafe('chat_history', ['session_id' => $sessionId], 'id', false);

$withReminder = 0;
foreach ($rows as $row) {
    $reminder = trim((string) ($row['turn_reminder'] ?? ''));
    if ($reminder === '') {
        continue;
    }
    $withReminder++;
    printf(
        "row %d  %-14s  reminder=%d chars  starts: %s\n",
        $row['id'],
        (string) ($row['message_type'] ?? '?'),
        strlen($reminder),
        substr(str_replace("\n", ' ', $reminder), 0, 40)
    );
}

printf(
    "session %d: %d rows, %d carrying a turn reminder\n",
    $sessionId,
    count($rows),
    $withReminder
);
if ($withReminder === 0) {
    echo "note: 0 is expected for turns before the change, and for turns that never ran a tool\n";
}
