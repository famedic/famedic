<?php

namespace App\Services\Laboratory;

use App\Models\LabOrderEventReceipt;
use App\Models\LabOrderEventState;
use App\Models\LaboratoryPurchase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LabOrderNotificationGateService
{
    public const EVENT_SAMPLE = 'sample';
    public const EVENT_RESULTS = 'results';

    public function registerEvent(
        string $gdaOrderId,
        string $eventType,
        ?LaboratoryPurchase $purchase,
        ?string $studyExternalId,
        ?string $providerEventId,
        array $payload
    ): array {
        $wasNewEvent = false;

        $state = DB::transaction(function () use (
            $gdaOrderId,
            $eventType,
            $purchase,
            $studyExternalId,
            $providerEventId,
            $payload,
            &$wasNewEvent
        ) {
            $state = LabOrderEventState::query()
                ->where('gda_order_id', $gdaOrderId)
                ->lockForUpdate()
                ->first();

            if (! $state) {
                $totalStudies = max(0, (int) ($purchase?->laboratoryPurchaseItems()->count() ?? 0));

                $state = LabOrderEventState::create([
                    'gda_order_id' => $gdaOrderId,
                    'laboratory_purchase_id' => $purchase?->id,
                    'total_studies' => $totalStudies,
                    'first_event_at' => now(),
                    'last_event_at' => now(),
                ]);
            } else {
                if (! $state->laboratory_purchase_id && $purchase?->id) {
                    $state->laboratory_purchase_id = $purchase->id;
                }

                if ($state->total_studies <= 0 && $purchase) {
                    $state->total_studies = (int) $purchase->laboratoryPurchaseItems()->count();
                }
            }

            $payloadHash = hash('sha256', json_encode($payload));

            try {
                LabOrderEventReceipt::query()->create([
                    'lab_order_event_state_id' => $state->id,
                    'event_type' => $eventType,
                    'study_external_id' => $studyExternalId ?: null,
                    'provider_event_id' => $providerEventId ?: null,
                    'payload_hash' => $payloadHash,
                ]);

                $wasNewEvent = true;
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyException($e)) {
                    throw $e;
                }

                Log::info('Duplicate webhook event ignored', [
                    'gda_order_id' => $gdaOrderId,
                    'event_type' => $eventType,
                    'study_external_id' => $studyExternalId,
                    'provider_event_id' => $providerEventId,
                ]);
            }

            if ($wasNewEvent) {
                if ($eventType === self::EVENT_SAMPLE) {
                    $state->sample_received_count += 1;
                }

                if ($eventType === self::EVENT_RESULTS) {
                    $state->results_received_count += 1;
                }
            }

            $state->last_event_at = now();
            $state->save();

            return $state->fresh();
        });

        $effectiveTotalStudies = max(1, (int) $state->total_studies);

        return [
            'state' => $state,
            'is_new_event' => $wasNewEvent,
            'should_send_sample_email' =>
                $state->sample_received_count >= $effectiveTotalStudies
                && is_null($state->sample_email_sent_at),
            'should_send_results_email' =>
                $state->results_received_count >= 1
                && is_null($state->results_email_sent_at),
        ];
    }

    public function sendSampleOnce(string $gdaOrderId, callable $callback): bool
    {
        return $this->sendOnce($gdaOrderId, self::EVENT_SAMPLE, $callback);
    }

    public function sendResultsOnce(string $gdaOrderId, callable $callback): bool
    {
        return $this->sendOnce($gdaOrderId, self::EVENT_RESULTS, $callback);
    }

    protected function sendOnce(string $gdaOrderId, string $eventType, callable $callback): bool
    {
        return DB::transaction(function () use ($gdaOrderId, $eventType, $callback) {
            $state = LabOrderEventState::query()
                ->where('gda_order_id', $gdaOrderId)
                ->lockForUpdate()
                ->first();

            if (! $state) {
                return false;
            }

            if ($eventType === self::EVENT_SAMPLE) {
                $effectiveTotalStudies = max(1, (int) $state->total_studies);
                $isReady = $state->sample_received_count >= $effectiveTotalStudies;
                $alreadySent = ! is_null($state->sample_email_sent_at);
            } else {
                $isReady = $state->results_received_count >= 1;
                $alreadySent = ! is_null($state->results_email_sent_at);
            }

            if (! $isReady || $alreadySent) {
                return false;
            }

            $callback();

            if ($eventType === self::EVENT_SAMPLE) {
                $state->sample_email_sent_at = now();
                $state->sample_tag_sent_at = now();
            } else {
                $state->results_email_sent_at = now();
                $state->results_tag_sent_at = now();
            }

            $state->save();

            return true;
        });
    }

    protected function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        // MySQL/MariaDB duplicate key = SQLSTATE 23000, error code 1062.
        return $sqlState === '23000' || (int) $driverCode === 1062;
    }
}
