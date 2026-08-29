<?php

namespace App\Agents;

use App\AgentManager;
use App\JsonParser;

/**
 * Cheap, recall-biased semantic triage for the daily briefing.
 *
 * Given every fetched email as (id + sender + subject + short preview), asks the
 * model which ids are worth reading in full. The hard invariant is "never
 * under-include": over-inclusion is cheap (extraction reads a few extra bodies),
 * a false miss is not. The model emits only a JSON id list — never card HTML and
 * never a fragile tag. PHP owns id validation.
 */
class BriefingTriage
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    /**
     * @param array $emails list of ['id' => int, 'preview' => string, ...]
     * @return int[] selected ids (sorted, validated)
     */
    public function select(array $emails): array
    {
        if (empty($emails)) {
            return [];
        }

        $allIds = [];
        foreach ($emails as $e) {
            if (isset($e['id'])) {
                $allIds[] = (int) $e['id'];
            }
        }
        if (empty($allIds)) {
            return [];
        }

        $prompt = self::buildPrompt($emails);
        $raw = $this->agent->chat(
            [['role' => 'system', 'content' => $prompt]],
            false,
            null,
            null,
            'briefing_triage',
            'none'
        );

        $decoded = JsonParser::extractAndDecode($raw) ?: [];
        return self::normalizeSelection($decoded, $allIds);
    }

    public static function buildPrompt(array $emails): string
    {
        $list = '';
        foreach ($emails as $e) {
            $id = (int) ($e['id'] ?? 0);
            $preview = (string) ($e['preview'] ?? '');
            $list .= "[{$id}] {$preview}\n";
        }

        return "You are a recall-biased triage step for a daily email briefing.\n"
            . "Each email below has a stable integer id. Decide which ids are worth reading in full for a useful daily briefing.\n\n"
            . "STRICTLY EXCLUDE only what you are CONFIDENT adds nothing: promotional offers, marketing spam, newsletters, advertisements, receipts, shipping/tracking updates, and automated alerts.\n"
            . "When uncertain, INCLUDE it — a false miss is far worse than a false include.\n"
            . "Personal commitments, meetings, reservations, pickups, bills, account or security notices, and any human correspondence are almost always worth including.\n\n"
            . "Emails:\n" . $list . "\n"
            . "Return ONLY a JSON array of the integer ids to include, e.g. [1,3,5]. If none are worth reading, return [].";
    }

    /**
     * Pure: coerce the decoded LLM output into a sorted, validated id list.
     * Accepts flat int arrays, arrays of {id:..} objects, and a wrapped {ids:[..]}.
     * Safety net: an empty result with non-empty candidates returns ALL ids
     * (never silently under-include because of a parse/format failure).
     *
     * @return int[]
     */
    public static function normalizeSelection(array $decoded, array $allIds): array
    {
        $allowed = array_flip(array_map('intval', $allIds));

        $raw = [];
        if (isset($decoded['ids']) && is_array($decoded['ids'])) {
            $raw = $decoded['ids'];
        } else {
            $raw = $decoded;
        }

        $selected = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                if (isset($item['id'])) {
                    $selected[] = (int) $item['id'];
                }
                continue;
            }
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $selected[] = (int) $item;
            }
        }

        $out = [];
        foreach ($selected as $id) {
            if (isset($allowed[$id])) {
                $out[$id] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids);

        if (empty($ids) && !empty($allIds)) {
            return array_map('intval', $allIds);
        }

        return $ids;
    }
}
