<?php

namespace App\Actions;

use App\Database;

/**
 * Records a thumbs up/down on an assistant reply. On a downvote the client must
 * supply a structured reason (no free-text) — this feeds the per-model
 * satisfaction metric in /models.php.
 *
 * Payload (JSON body):
 *   message_id  int     assistant chat_history row id
 *   rating      int    1 (up) | 0 (down) | null (clear)
 *   reason      string downvote reason key (required when rating = 0)
 */
class RateReplyAction extends BaseAction
{
    public const DOWNVOTE_REASONS = [
        'wrong'         => 'Factually wrong / hallucinated',
        'unhelpful'     => 'Did not address my question',
        'too_verbose'   => 'Too long / rambling',
        'too_brief'     => 'Too short / incomplete',
        'bad_format'    => 'Poor formatting / unreadable',
        'bad_query'     => 'Poor search query (low-yield)',
        'wrong_tool'    => 'Chose the wrong tool',
        'bad_tool_args' => 'Malformed / irrelevant tool arguments',
        'other'         => 'Other',
    ];

    /** Subset of DOWNVOTE_REASONS shown only for tool-use turns. */
    public const TOOL_TURN_REASONS = ['bad_query', 'wrong_tool', 'bad_tool_args'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Pure rating-resolution logic (no DB) so it is unit-testable without a
     * connection. Returns the final rating/reason or an error message.
     *
     * @return array{rating: ?int, reason: ?string, error: ?string}
     */
    public static function resolveRating(?int $incomingRating, ?string $incomingReason, ?int $currentRating): array
    {
        $rating = $incomingRating;
        $reason = $incomingReason;

        if ($rating !== null && $rating !== 0 && $rating !== 1) {
            return ['rating' => null, 'reason' => null, 'error' => 'Invalid rating.'];
        }
        if ($rating === 0 && !array_key_exists((string)$reason, self::DOWNVOTE_REASONS)) {
            return ['rating' => null, 'reason' => null, 'error' => 'A reason is required for a downvote.'];
        }
        if ($rating !== 0) {
            $reason = null;
        }
        // Toggle: rating the same value again clears it (and its reason).
        if ($rating !== null && $currentRating === $rating) {
            $rating = null;
            $reason = null;
        }

        return ['rating' => $rating, 'reason' => $reason, 'error' => null];
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid payload.'], 400);
            return;
        }

        $messageId = (int)($in['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid message_id.'], 400);
            return;
        }

        $incomingRating = $in['rating'] ?? null;
        if ($incomingRating !== null) {
            $incomingRating = (int)$incomingRating;
        }
        $incomingReason = isset($in['reason']) && is_string($in['reason']) ? $in['reason'] : null;

        // Validate target is an assistant row.
        $rows = $this->db->query(
            "SELECT rating FROM chat_history WHERE id = ? AND role = 'assistant'",
            [$messageId]
        );
        if (empty($rows)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Not found.'], 404);
            return;
        }
        $currentRating = $rows[0]['rating'] === null ? null : (int)$rows[0]['rating'];

        $resolved = self::resolveRating($incomingRating, $incomingReason, $currentRating);
        if ($resolved['error'] !== null) {
            $this->jsonResponse(['status' => 'error', 'message' => $resolved['error']], 400);
            return;
        }

        $this->db->update('chat_history', [
            'rating' => $resolved['rating'],
            'rating_reason' => $resolved['reason'],
        ], ['id' => $messageId]);

        $this->jsonResponse(['status' => 'ok', 'rating' => $resolved['rating'], 'reason' => $resolved['reason']]);
    }
}
