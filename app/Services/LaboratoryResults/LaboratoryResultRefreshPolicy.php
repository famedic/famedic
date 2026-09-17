<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use Carbon\CarbonInterface;

class LaboratoryResultRefreshPolicy
{
    public function enabled(): bool
    {
        return (bool) config('services.gda.result_refresh.enabled', false);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('services.gda.result_refresh.max_attempts', 5));
    }

    /**
     * @return list<int>
     */
    public function backoffMinutes(): array
    {
        $minutes = config('services.gda.result_refresh.backoff_minutes', [30, 60, 120, 240, 480]);

        if (! is_array($minutes) || $minutes === []) {
            return [30, 60, 120, 240, 480];
        }

        return array_values(array_map(
            fn ($value) => max(1, (int) $value),
            $minutes
        ));
    }

    public function isRefreshable(LaboratoryResultStatus $status): bool
    {
        return $status->status === LaboratoryResultStatusEnum::PendingInterpretation
            && $status->next_check_at !== null
            && $status->check_attempts < $this->maxAttempts();
    }

    public function scheduleInitialPendingIfNeeded(
        LaboratoryResultStatus $status,
        ?LaboratoryResultVersion $version = null,
        string $reason = 'pending_interpretation',
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $status->refresh();

        if ($status->status !== LaboratoryResultStatusEnum::PendingInterpretation) {
            return;
        }

        if ($status->next_check_at !== null) {
            return;
        }

        if ($status->check_attempts >= $this->maxAttempts()) {
            return;
        }

        $status->forceFill([
            'next_check_at' => $this->nextCheckAt($status->check_attempts + 1),
        ])->save();

        $this->recordEvent(
            $status,
            $version,
            LaboratoryResultEventType::RefreshScheduled,
            metadata: [
                'reason' => $reason,
                'attempt' => $status->check_attempts + 1,
            ],
        );
    }

    public function markTerminal(
        LaboratoryResultStatus $status,
        ?LaboratoryResultVersion $version = null,
        string $reason = 'terminal_status',
    ): void {
        if ($status->next_check_at === null) {
            return;
        }

        $status->forceFill(['next_check_at' => null])->save();

        $this->recordEvent(
            $status,
            $version,
            LaboratoryResultEventType::RefreshChecked,
            metadata: ['reason' => $reason],
        );
    }

    public function recordPendingRefreshAttempt(
        LaboratoryResultStatus $status,
        ?LaboratoryResultVersion $version = null,
        string $reason = 'pdf_unchanged',
    ): void {
        $status->refresh();
        $nextAttempts = $status->check_attempts + 1;

        if ($nextAttempts >= $this->maxAttempts()) {
            $fromStatus = $status->status;

            $status->forceFill([
                'status' => LaboratoryResultStatusEnum::ManualReview,
                'check_attempts' => $nextAttempts,
                'last_checked_at' => now(),
                'next_check_at' => null,
            ])->save();

            $this->recordEvent(
                $status,
                $version,
                LaboratoryResultEventType::RefreshAttemptsExhausted,
                fromStatus: $fromStatus,
                toStatus: LaboratoryResultStatusEnum::ManualReview,
                metadata: [
                    'reason' => 'refresh_attempts_exhausted',
                    'attempt' => $nextAttempts,
                    'max_attempts' => $this->maxAttempts(),
                ],
            );

            return;
        }

        $status->forceFill([
            'check_attempts' => $nextAttempts,
            'last_checked_at' => now(),
            'next_check_at' => $this->nextCheckAt($nextAttempts + 1),
        ])->save();

        $this->recordEvent(
            $status,
            $version,
            LaboratoryResultEventType::RefreshChecked,
            metadata: [
                'reason' => $reason,
                'attempt' => $nextAttempts,
                'next_attempt' => $nextAttempts + 1,
            ],
        );
    }

    public function nextCheckAt(int $attempt): CarbonInterface
    {
        $backoff = $this->backoffMinutes();
        $delay = $backoff[max(0, min($attempt, count($backoff)) - 1)] ?? end($backoff);

        return now()->addMinutes((int) $delay);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordEvent(
        LaboratoryResultStatus $status,
        ?LaboratoryResultVersion $version,
        LaboratoryResultEventType $eventType,
        ?LaboratoryResultStatusEnum $fromStatus = null,
        ?LaboratoryResultStatusEnum $toStatus = null,
        ?array $metadata = null,
    ): void {
        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_result_version_id' => $version?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
