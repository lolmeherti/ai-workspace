<?php

namespace App\Search;

final class SourceSequence
{
    /**
     * Next session-global source ID for a chat session. Source IDs are
     * session-global monotonic, derived from persisted data_fetching source_maps
     * (max S\d+ + 1). A session with no persisted evidence starts at S1.
     */
    public static function nextSourceSeq(\App\Database $db, int $sessionId): int
    {
        $max = 0;
        $rows = $db->query(
            "SELECT source_map FROM chat_history WHERE session_id = ? AND message_type = 'data_fetching'",
            [$sessionId]
        );
        foreach ($rows as $row) {
            $map = self::decodeMap($row['source_map'] ?? null);
            if ($map === null) {
                continue;
            }
            $max = max($max, self::maxSeqFromMap($map));
        }
        return $max + 1;
    }

    /**
     * Highest S# index present in a source map, or 0 when none.
     */
    public static function maxSeqFromMap(array $sourceMap): int
    {
        $max = 0;
        foreach (array_keys($sourceMap) as $key) {
            if (preg_match('/^S(\d+)$/', (string)$key, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $max;
    }

    private static function decodeMap($raw): ?array
    {
        if (empty($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
