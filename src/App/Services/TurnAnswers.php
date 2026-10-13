<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * The terminal link: which user row(s) a terminal row closes.
 *
 * A terminal row is either the assistant answer or the stop's closing line. This link is what makes "is this turn
 * answered" a fact — a query — instead of an ordering guess, and the table's primary key is what makes a second answer
 * to the same user row impossible rather than merely unlikely: `record()` returns false instead of writing, and the
 * caller rolls back the terminal row it just inserted.
 */
final class TurnAnswers
{
    public const KIND_ANSWER = 'answer';
    public const KIND_STOPPED = 'stopped';

    public function __construct(private Database $db)
    {
    }

    /**
     * Record the link for every user row a terminal row closes.
     *
     * @param array<int,int|string> $userRowIds
     * @return bool false when one of those rows is already closed — nothing is written for that row, and the caller
     *              must roll its own terminal row back with it.
     */
    public function record(array $userRowIds, int $terminalRowId, string $kind = self::KIND_ANSWER): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userRowIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === [] || $terminalRowId <= 0) {
            return true;
        }

        foreach ($ids as $userRowId) {
            try {
                $this->db->insert('turn_answers', [
                    'user_row_id' => $userRowId,
                    'terminal_row_id' => $terminalRowId,
                    'kind' => $kind,
                ]);
            } catch (\PDOException $e) {
                // 23xxx = integrity violation: that user row already has a terminal row.
                if (str_starts_with((string)$e->getCode(), '23')) {
                    return false;
                }
                throw $e;
            }
        }

        return true;
    }

    /**
     * The answer row that closes this conversation's NEWEST user row, or null while that turn is still owed.
     *
     * The "newest" part is the whole point: a viewer that arrives after a turn finished is served this row, while a
     * viewer waiting on a turn that has not been answered yet gets null — the previous answer is never handed over as if
     * it were this turn's, which is the one thing a fallback like this can get catastrophically wrong.
     */
    public function storedAnswerRowId(int $sessionId): ?int
    {
        $rows = $this->db->query(
            "SELECT t.terminal_row_id
               FROM chat_history u
               LEFT JOIN turn_answers t ON t.user_row_id = u.id
              WHERE u.session_id = ? AND u.role = 'user'
              ORDER BY u.id DESC LIMIT 1",
            [$sessionId]
        );
        if ($rows === [] || $rows[0]['terminal_row_id'] === null) {
            return null;
        }

        return (int)$rows[0]['terminal_row_id'];
    }

    /**
     * The user rows a terminal row closes. A batch answer closes several.
     *
     * @return array<int,int>
     */
    public function userRowIdsOf(int $terminalRowId): array
    {
        $rows = $this->db->query(
            "SELECT user_row_id FROM turn_answers WHERE terminal_row_id = ? ORDER BY user_row_id",
            [$terminalRowId]
        );

        return array_map(static fn(array $row): int => (int)$row['user_row_id'], $rows);
    }

    /**
     * The terminal row for a user row, or null while the turn is still owed.
     *
     * @return array{terminal_row_id:int,kind:string}|null
     */
    public function terminalOf(int $userRowId): ?array
    {
        $rows = $this->db->query(
            "SELECT terminal_row_id, kind FROM turn_answers WHERE user_row_id = ?",
            [$userRowId]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'terminal_row_id' => (int)$rows[0]['terminal_row_id'],
            'kind' => (string)$rows[0]['kind'],
        ];
    }
}
