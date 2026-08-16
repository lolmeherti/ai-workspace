<?php

namespace App\Jobs;

use App\Enums\JobState;
use App\Enums\JobHistoryReason;
use InvalidArgumentException;

class JobStateMachine
{
    private const TRANSITIONS = [
        JobState::UNREAD->value => [JobState::INTERESTED, JobState::HISTORY],
        JobState::INTERESTED->value => [JobState::APPLIED, JobState::HISTORY],
        JobState::APPLIED->value => [JobState::INTERVIEW, JobState::HISTORY],
        JobState::INTERVIEW->value => [JobState::OFFER, JobState::HISTORY],
        JobState::OFFER->value => [JobState::HISTORY],
        JobState::HISTORY->value => [],
    ];

    private const TIMESTAMP_COLUMN = [
        JobState::INTERESTED->value => 'interested_at',
        JobState::APPLIED->value => 'applied_at',
        JobState::INTERVIEW->value => 'interview_at',
        JobState::OFFER->value => 'offer_at',
        JobState::HISTORY->value => 'history_at',
    ];

    public function transition(array $job, JobState $to, ?JobHistoryReason $reason = null, ?string $at = null): array
    {
        $from = JobState::from($job['state']);

        if (!in_array($to, self::TRANSITIONS[$from->value], true)) {
            throw new InvalidArgumentException("Invalid transition: {$from->value} -> {$to->value}");
        }

        if ($to === JobState::HISTORY && $reason === null) {
            throw new InvalidArgumentException('A history transition requires a reason');
        }

        $now = $at ?? date('Y-m-d H:i:s');

        $job['state'] = $to->value;
        $job['state_timestamps'] = $this->appendTransition($job['state_timestamps'] ?? null, $from, $to, $now);
        $job['history_reason'] = $to === JobState::HISTORY ? $reason->value : null;

        if (isset(self::TIMESTAMP_COLUMN[$to->value])) {
            $job[self::TIMESTAMP_COLUMN[$to->value]] = $now;
        }

        return $job;
    }

    public function restore(array $job): array
    {
        $current = JobState::from($job['state']);
        if ($current !== JobState::HISTORY) {
            throw new InvalidArgumentException('Only history jobs can be restored');
        }

        $timestamps = $this->decode($job['state_timestamps'] ?? null);
        $target = $this->terminalFrom($timestamps);

        $now = date('Y-m-d H:i:s');
        $timestamps[] = ['from' => JobState::HISTORY->value, 'to' => $target->value, 'at' => $now];

        $job['state'] = $target->value;
        $job['state_timestamps'] = $this->encode($timestamps);
        $job['history_reason'] = null;
        $job['history_at'] = null;

        return $job;
    }

    private function terminalFrom(array $timestamps): JobState
    {
        for ($i = count($timestamps) - 1; $i >= 0; $i--) {
            if (($timestamps[$i]['to'] ?? '') === JobState::HISTORY->value) {
                return JobState::from($timestamps[$i]['from']);
            }
        }
        throw new InvalidArgumentException('History job has no terminal transition recorded');
    }

    private function appendTransition(?string $json, JobState $from, JobState $to, string $at): string
    {
        $timestamps = $this->decode($json);
        $timestamps[] = ['from' => $from->value, 'to' => $to->value, 'at' => $at];
        return $this->encode($timestamps);
    }

    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $timestamps): string
    {
        return json_encode(array_values($timestamps));
    }
}
