<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActiveCampaignDispatchService
{
    /** @var list<string> */
    private const COUPON_EVENT_PREFIXES = [
        'credit_',
        'promo_',
        'pending_beneficiary_',
        'authorization_',
    ];

    /** @var list<string> */
    private const SENSITIVE_PAYLOAD_KEYS = [
        'otp',
        'validation_token',
        'authorization_code',
        'card',
        'card_number',
        'cvv',
        'cvc',
        'pan',
        'password',
        'token',
        'api_token',
        'secret',
    ];

    public function isEnabled(): bool
    {
        return (bool) config('services.activecampaign.enabled', true);
    }

    public function isCouponsEnabled(): bool
    {
        return $this->isEnabled()
            && (bool) config('services.activecampaign.coupons_enabled', true);
    }

    public function isCouponsExpiringEnabled(): bool
    {
        return $this->isCouponsEnabled()
            && (bool) config('services.activecampaign.coupons_expiring_enabled', false);
    }

    public function isEnabledForCoupons(): bool
    {
        return $this->isCouponsEnabled();
    }

    public function isCouponEvent(string $eventType): bool
    {
        foreach (self::COUPON_EVENT_PREFIXES as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function shouldDispatch(string $idempotencyKey, ?string $eventType = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($eventType !== null && $this->isCouponEvent($eventType) && ! $this->isCouponsEnabled()) {
            return false;
        }

        $existing = ActiveCampaignDispatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return true;
        }

        if ($existing->status === ActiveCampaignDispatch::STATUS_FAILED) {
            return true;
        }

        return ! $existing->isInFlight() && ! $existing->isTerminal();
    }

    /**
     * @param  array{
     *     event_type: string,
     *     idempotency_key: string,
     *     entity_type: string,
     *     entity_id?: int|null,
     *     related_entity_type?: string|null,
     *     related_entity_id?: int|null,
     *     user_id?: int|null,
     *     customer_id?: int|null,
     *     email?: string|null,
     *     payload?: array<string, mixed>|null
     * }  $data
     */
    public function createOrSkipByIdempotencyKey(array $data): ActiveCampaignDispatch
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $eventType = (string) $data['event_type'];

        $existing = ActiveCampaignDispatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->isEnabled()) {
            return $this->createDispatch($data, ActiveCampaignDispatch::STATUS_SKIPPED, 'integration_disabled');
        }

        if ($this->isCouponEvent($eventType) && ! $this->isCouponsEnabled()) {
            return $this->createDispatch($data, ActiveCampaignDispatch::STATUS_SKIPPED, 'coupons_integration_disabled');
        }

        return $this->createDispatch($data, ActiveCampaignDispatch::STATUS_PENDING);
    }

    public function markProcessing(ActiveCampaignDispatch $dispatch): void
    {
        $dispatch->fill([
            'status' => ActiveCampaignDispatch::STATUS_PROCESSING,
            'last_error' => null,
        ]);
        $dispatch->save();

        Log::info('AC Dispatch: processing', $this->logContext($dispatch));
    }

    public function markSynced(ActiveCampaignDispatch $dispatch): void
    {
        $dispatch->fill([
            'status' => ActiveCampaignDispatch::STATUS_SYNCED,
            'synced_at' => now(),
            'last_error' => null,
        ]);
        $dispatch->save();

        Log::info('AC Dispatch: synced', $this->logContext($dispatch));
    }

    public function markFailed(ActiveCampaignDispatch $dispatch, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $dispatch->fill([
            'status' => ActiveCampaignDispatch::STATUS_FAILED,
            'attempts' => (int) $dispatch->attempts + 1,
            'last_error' => $message,
        ]);
        $dispatch->save();

        Log::error('AC Dispatch: failed', array_merge($this->logContext($dispatch), [
            'error' => $message,
        ]));
    }

    public function markSkipped(ActiveCampaignDispatch $dispatch, ?string $reason = null): void
    {
        $dispatch->fill([
            'status' => ActiveCampaignDispatch::STATUS_SKIPPED,
            'last_error' => $reason,
        ]);
        $dispatch->save();

        Log::info('AC Dispatch: skipped', array_merge($this->logContext($dispatch), [
            'reason' => $reason,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizePayloadForLog(array $payload): array
    {
        return $this->redactSensitiveKeys($payload);
    }

    /**
     * @param  array{
     *     event_type: string,
     *     idempotency_key: string,
     *     entity_type: string,
     *     entity_id?: int|null,
     *     related_entity_type?: string|null,
     *     related_entity_id?: int|null,
     *     user_id?: int|null,
     *     customer_id?: int|null,
     *     email?: string|null,
     *     payload?: array<string, mixed>|null
     * }  $data
     */
    private function createDispatch(array $data, string $status, ?string $skipReason = null): ActiveCampaignDispatch
    {
        $businessPayload = $data['payload'] ?? [];

        return ActiveCampaignDispatch::query()->create([
            'event_type' => $data['event_type'],
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'] ?? null,
            'related_entity_type' => $data['related_entity_type'] ?? null,
            'related_entity_id' => $data['related_entity_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'email' => $data['email'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
            'status' => $status,
            'attempts' => 0,
            'last_error' => $skipReason,
            'payload' => $businessPayload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(ActiveCampaignDispatch $dispatch): array
    {
        return [
            'dispatch_id' => $dispatch->id,
            'event_type' => $dispatch->event_type,
            'idempotency_key' => $dispatch->idempotency_key,
            'status' => $dispatch->status,
            'entity_type' => $dispatch->entity_type,
            'entity_id' => $dispatch->entity_id,
            'payload' => $this->sanitizePayloadForLog($dispatch->payload ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactSensitiveKeys(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactSensitiveKeys($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_PAYLOAD_KEYS as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
