<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Exceptions\PaymentAuthenticationSensitiveCardDataContainmentDisabledException;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class PaymentAuthenticationSensitiveCardDataStore
{
    public const LEGACY_SESSION_KEY = '3ds_card_data';

    public function __construct(
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthenticationSensitiveCardDataMetrics $metrics
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('efevoopay.sensitive_card_data.containment_enabled', true);
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('efevoopay.sensitive_card_data.ttl_minutes', 5));
    }

    public function sessionKey(int $efevoo3dsSessionId): string
    {
        return '3ds_card_data_'.$efevoo3dsSessionId;
    }

    /**
     * @param  array<string, mixed>  $cardData
     */
    public function store(
        Customer $customer,
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt,
        array $cardData
    ): void {
        $this->assertContainmentEnabledForStorage();

        $now = now();
        $payload = array_merge($this->cardFieldsOnly($cardData), [
            'stored_at' => $now->timestamp,
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes())->timestamp,
            'customer_id' => (int) $customer->id,
            'authentication_attempt_id' => $attempt?->id,
            'efevoo_3ds_session_id' => (int) $session->id,
        ]);

        Session::put($this->sessionKey($session->id), $payload);
        $this->metrics->recordStored();

        if ($attempt) {
            $this->recordSensitiveEvent($attempt, PaymentAuthenticationAttemptEventType::SensitiveCardDataStored, [
                'reason' => 'challenge_pending',
                'stage' => 'store_after_get_link',
                'expires_at' => $payload['expires_at'],
                'session_id' => $session->id,
                'attempt_id' => $attempt->id,
                'detected_by' => 'famedic',
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null Card fields for provider calls, or null when absent/expired/unauthorized.
     */
    public function readForCustomer(Customer $customer, int $efevoo3dsSessionId): ?array
    {
        $payload = $this->rawPayload($efevoo3dsSessionId);

        if ($payload === null) {
            return null;
        }

        if (! $this->isEnabled()) {
            return null;
        }

        if ((int) ($payload['customer_id'] ?? 0) !== (int) $customer->id) {
            return null;
        }

        if ($this->payloadExpired($payload)) {
            $this->purge($efevoo3dsSessionId, 'ttl_expired', null, [
                'stage' => 'read',
                'detected_by' => 'famedic',
            ]);
            $this->metrics->recordExpired();

            return null;
        }

        return $this->cardFieldsOnly($payload);
    }

    public function hasValidForCustomer(Customer $customer, int $efevoo3dsSessionId): bool
    {
        return $this->readForCustomer($customer, $efevoo3dsSessionId) !== null;
    }

    public function purge(
        int $efevoo3dsSessionId,
        string $reason,
        ?PaymentAuthenticationAttempt $attempt = null,
        array $metadata = []
    ): bool {
        $key = $this->sessionKey($efevoo3dsSessionId);
        $existed = Session::has($key);

        Session::forget($key);

        if ($existed) {
            $this->metrics->recordPurged($reason);

            if ($attempt) {
                $this->recordSensitiveEvent($attempt, PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged, array_merge([
                    'reason' => $reason,
                    'stage' => $metadata['stage'] ?? 'purge',
                    'session_id' => $efevoo3dsSessionId,
                    'attempt_id' => $attempt->id,
                    'detected_by' => $metadata['detected_by'] ?? 'famedic',
                ], $metadata));
            }
        }

        return $existed;
    }

    public function purgeByAttempt(
        PaymentAuthenticationAttempt $attempt,
        string $reason,
        array $metadata = []
    ): bool {
        $sessionId = $attempt->efevoo_3ds_session_id;

        if (! $sessionId) {
            return false;
        }

        return $this->purge((int) $sessionId, $reason, $attempt, $metadata);
    }

    public function purgeForRecoveryContext(
        \App\Models\PaymentAuthenticationRecoveryContext $context,
        string $reason,
        array $metadata = []
    ): int {
        $purged = 0;

        \App\Models\PaymentAuthenticationAttempt::query()
            ->where('recovery_context_id', $context->id)
            ->whereNotNull('efevoo_3ds_session_id')
            ->each(function (PaymentAuthenticationAttempt $attempt) use ($reason, $metadata, &$purged) {
                if ($this->purgeByAttempt($attempt, $reason, $metadata)) {
                    $purged++;
                }
            });

        return $purged;
    }

    public function purgeLegacyGlobal(): bool
    {
        $existed = Session::has(self::LEGACY_SESSION_KEY);
        Session::forget(self::LEGACY_SESSION_KEY);

        return $existed;
    }

    public function assertAbsent(int $efevoo3dsSessionId): bool
    {
        return ! Session::has($this->sessionKey($efevoo3dsSessionId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rawPayload(int $efevoo3dsSessionId): ?array
    {
        $payload = Session::get($this->sessionKey($efevoo3dsSessionId));

        return is_array($payload) ? $payload : null;
    }

    public function payloadExpired(array $payload): bool
    {
        $expiresAt = $payload['expires_at'] ?? null;

        if (! is_int($expiresAt) && ! is_numeric($expiresAt)) {
            return false;
        }

        return (int) $expiresAt <= now()->timestamp;
    }

    public function recordMissing(
        PaymentAuthenticationAttempt $attempt,
        string $reason,
        array $metadata = []
    ): void {
        $this->metrics->recordMissing();
        $this->recordSensitiveEvent($attempt, PaymentAuthenticationAttemptEventType::SensitiveCardDataMissing, array_merge([
            'reason' => $reason,
            'stage' => $metadata['stage'] ?? 'poll',
            'session_id' => $attempt->efevoo_3ds_session_id,
            'attempt_id' => $attempt->id,
            'detected_by' => $metadata['detected_by'] ?? 'famedic',
        ], $metadata));
    }

    public function recordExpiredEvent(PaymentAuthenticationAttempt $attempt, array $metadata = []): void
    {
        $this->metrics->recordExpired();
        $this->recordSensitiveEvent($attempt, PaymentAuthenticationAttemptEventType::SensitiveCardDataExpired, array_merge([
            'reason' => 'ttl_expired',
            'stage' => $metadata['stage'] ?? 'read',
            'session_id' => $attempt->efevoo_3ds_session_id,
            'attempt_id' => $attempt->id,
            'detected_by' => 'famedic',
        ], $metadata));
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    public function stripCvv(array $cardData): array
    {
        unset($cardData['cvv']);

        return $cardData;
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    private function cardFieldsOnly(array $cardData): array
    {
        return array_intersect_key($cardData, array_flip([
            'card_number',
            'expiration',
            'cvv',
            'card_holder',
            'alias',
            'amount',
        ]));
    }

    public function assertContainmentEnabledForStorage(): void
    {
        if (! $this->isEnabled()) {
            Log::error('[3DS] Sensitive card data containment disabled — blocking new storage', [
                'environment' => app()->environment(),
            ]);

            throw new PaymentAuthenticationSensitiveCardDataContainmentDisabledException;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordSensitiveEvent(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEventType $eventType,
        array $metadata
    ): void {
        $allowed = array_intersect_key($metadata, array_flip([
            'reason',
            'stage',
            'expires_at',
            'session_id',
            'attempt_id',
            'detected_by',
        ]));

        try {
            $this->recorder->record($attempt->fresh(), $eventType, [
                'source' => 'backend',
                'dedupe_key' => $eventType->value.':'.$attempt->id.':'.($allowed['reason'] ?? 'unknown').':'.($allowed['stage'] ?? 'unknown'),
                'metadata' => $allowed,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[3DS] Sensitive card data event not recorded', [
                'attempt_id' => $attempt->id,
                'event_type' => $eventType->value,
                'exception_class' => $e::class,
            ]);
        }
    }
}
