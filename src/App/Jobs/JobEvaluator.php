<?php

namespace App\Jobs;

use App\AgentManager;
use App\JsonParser;

class JobEvaluator
{
    private const TEMPERATURE = 0.3;

    private const EVAL_SYSTEM_PROMPT = <<<'PROMPT'
You judge whether a job posting is worth a candidate's attention. A different tech stack is not a rejection. Stated preferences are preferences, not hard filters. Reason broadly about professional relevance, seniority fit, and opportunity quality. Return a single JSON object: {"decision": "KEEP" or "DISCARD", "comment": "one short sentence explaining why"}. Output only the JSON object.
PROMPT;

    public function __construct(private AgentManager $agentManager)
    {
    }

    public function evaluate(array $job, string $cvMarkdown, array $profile): array
    {
        $user = "CANDIDATE PROFILE (Markdown):\n{$cvMarkdown}\n\n"
            . "PREFERENCES:\n" . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "JOB:\n" . json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->agentManager->chat([
            ['role' => 'system', 'content' => self::EVAL_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $user],
        ], false, null, self::TEMPERATURE);

        return self::parseVerdict($response);
    }

    public static function parseVerdict(string $response): array
    {
        $result = JsonParser::extractAndDecode($response);
        $decision = is_array($result) ? strtoupper(trim((string) ($result['decision'] ?? ''))) : '';
        if (!in_array($decision, ['KEEP', 'DISCARD'], true)) {
            $decision = 'DISCARD';
        }
        $comment = is_array($result) ? trim((string) ($result['comment'] ?? '')) : '';
        return ['decision' => $decision, 'comment' => $comment];
    }
}
