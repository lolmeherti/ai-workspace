<?php

namespace App\Services;

/**
 * The LLM endpoint answered — but not with a completion. Either it returned a
 * non-2xx status, or it returned 2xx with an error payload instead of choices.
 *
 * Exists so that a failure can never be expressed as a success value: before
 * this, a 400 whose body carried a reason was parsed as a completion with no
 * choices, which surfaced to the user as an empty answer with no explanation
 * (see .hermes/plans/2026-10-02_080055-silent-failure-handling.md, task 1).
 *
 * The status and a trimmed body excerpt are carried so the failure is
 * attributable — logged as an event and surfaced through the existing error
 * channel — rather than silently becoming a blank bubble.
 */
class EndpointException extends \RuntimeException
{
    private int $status;
    private string $bodyExcerpt;

    public function __construct(int $status, string $body = '', string $purpose = '')
    {
        $this->status = $status;
        $this->bodyExcerpt = self::excerpt($body);

        $label = $purpose !== '' ? "{$purpose} request" : 'request';
        $detail = $this->bodyExcerpt !== '' ? ' — ' . $this->bodyExcerpt : ' (empty body)';

        parent::__construct("The AI engine answered the {$label} with HTTP {$status}{$detail}");
    }

    public function status(): int
    {
        return $this->status;
    }

    public function bodyExcerpt(): string
    {
        return $this->bodyExcerpt;
    }

    /**
     * Human-readable reason from the body: an OpenAI-style {"error":{"message":…}}
     * if present, otherwise the body's first 200 characters on one line. Never
     * includes headers or anything the caller did not already have.
     */
    private static function excerpt(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error) && is_string($error['message'] ?? null)) {
                return mb_substr($error['message'], 0, 200);
            }
            if (is_string($error)) {
                return mb_substr($error, 0, 200);
            }
            if (is_string($decoded['message'] ?? null)) {
                return mb_substr($decoded['message'], 0, 200);
            }
        }

        return mb_substr((string) preg_replace('/\s+/', ' ', $body), 0, 200);
    }
}
