<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class PaymentAuthenticationRecoveryNavigator
{
    public function __construct(
        private PaymentAuthenticationRecoveryPolicy $policy,
        private PaymentAuthenticationRecoveryContextGuard $guard,
        private PaymentAuthenticationRecoveryContextManager $contextManager,
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore
    ) {}

    /**
     * @return array{
     *     redirect_url: string,
     *     recovery_action: string,
     *     recovery_intent: string,
     *     attempts_remaining: int
     * }
     */
    public function start(
        Customer $customer,
        Efevoo3dsSession $session,
        PaymentAuthenticationRecoveryContext $context,
        string $recoveryAction
    ): array {
        $attempt = $session->paymentAuthenticationAttempt;

        if (! $attempt || (int) $attempt->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $this->guard->requireOwned($customer, $context);
        $this->contextManager->expireIfNeeded($context);
        $context = $context->fresh() ?? $context;

        $evaluation = $this->policy->evaluate($customer, $attempt->fresh(), $context, $recoveryAction);

        if (! $evaluation['allowed']) {
            $this->recordBlocked($attempt, $context, $recoveryAction, $evaluation);

            throw PaymentAuthenticationRecoveryStartException::blocked(
                $this->blockedMessage($evaluation['block_reason']),
                $evaluation['block_reason'],
                $evaluation
            );
        }

        $recoveryIntent = $this->policy->recoveryIntentForAction($recoveryAction);

        $purgeReason = $recoveryAction === PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD
            ? 'different_card'
            : 'retry';

        $this->cardDataStore->purgeByAttempt($attempt, $purgeReason, [
            'stage' => 'recovery_start',
            'detected_by' => 'famedic',
        ]);
        $this->cardDataStore->purgeLegacyGlobal();

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryStarted, [
            'source' => 'frontend',
            'dedupe_key' => 'recovery_started:'.$attempt->id.':'.$recoveryAction.':'.now()->timestamp,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->contextTypeValue($context),
                'recovery_action' => $recoveryIntent,
                'attempt_number' => $attempt->attempt_number,
                'attempts_remaining' => $evaluation['attempts_remaining'],
                'detected_by' => 'recovery_navigation',
            ],
        ]);

        if ($recoveryAction === PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD) {
            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChangedCard, [
                'source' => 'frontend',
                'dedupe_key' => 'changed_card:'.$attempt->id.':'.now()->timestamp,
                'metadata' => [
                    'context_uuid' => $context->context_uuid,
                    'context_type' => $this->contextTypeValue($context),
                    'recovery_action' => $recoveryIntent,
                    'attempt_number' => $attempt->attempt_number,
                    'detected_by' => 'recovery_navigation',
                ],
            ]);
        }

        $ttlMinutes = max(1, (int) config('efevoopay.recovery.navigation_session_ttl_minutes', 10));

        Session::put($this->sessionKey($customer), [
            'retry_of_attempt_id' => $attempt->id,
            'recovery_action' => $recoveryAction,
            'recovery_intent' => $recoveryIntent,
            'recovery_context_uuid' => $context->context_uuid,
            'source_session_id' => $session->id,
            'expires_at' => now()->addMinutes($ttlMinutes)->timestamp,
        ]);

        return [
            'redirect_url' => route('payment-methods.create', [
                'recovery_context_uuid' => $context->context_uuid,
            ]),
            'recovery_action' => $recoveryAction,
            'recovery_intent' => $recoveryIntent,
            'attempts_remaining' => $evaluation['attempts_remaining'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumePreparedRecovery(Customer $customer, ?string $recoveryContextUuid): ?array
    {
        $payload = Session::get($this->sessionKey($customer));

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            Session::forget($this->sessionKey($customer));

            return null;
        }

        if ($recoveryContextUuid !== null && ($payload['recovery_context_uuid'] ?? null) !== $recoveryContextUuid) {
            return null;
        }

        return $payload;
    }

    public function clearPreparedRecovery(Customer $customer): void
    {
        Session::forget($this->sessionKey($customer));
    }

    public function sessionKey(Customer $customer): string
    {
        return 'payment_auth_recovery_'.$customer->id;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function recordBlocked(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationRecoveryContext $context,
        string $recoveryAction,
        array $evaluation
    ): void {
        $blockReason = (string) ($evaluation['block_reason'] ?? 'unknown');
        $eventType = $blockReason === 'recovery_limit_reached'
            ? PaymentAuthenticationAttemptEventType::RecoveryLimitReached
            : PaymentAuthenticationAttemptEventType::RecoveryRetryBlocked;

        if ($blockReason === 'recovery_limit_reached' && ! ($evaluation['active_attempt'] ?? null)) {
            $this->cardDataStore->purgeByAttempt($attempt, 'recovery_limit_reached', [
                'stage' => 'recovery_blocked',
                'detected_by' => 'famedic',
            ]);
        }

        $this->recorder->record($attempt, $eventType, [
            'source' => 'frontend',
            'dedupe_key' => $eventType->value.':'.$attempt->id.':'.$blockReason.':'.now()->timestamp,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->contextTypeValue($context),
                'recovery_action' => $this->policy->recoveryIntentForAction($recoveryAction),
                'attempt_number' => $attempt->attempt_number,
                'attempts_remaining' => $evaluation['attempts_remaining'] ?? 0,
                'block_reason' => $blockReason,
                'detected_by' => 'recovery_navigation',
            ],
        ]);
    }

    private function blockedMessage(?string $blockReason): string
    {
        return match ($blockReason) {
            'active_attempt_exists' => 'Ya tienes una verificación en proceso.',
            'recovery_limit_reached' => 'Alcanzaste el número máximo de intentos de verificación. Comunícate con soporte o regresa más tarde.',
            'cooldown_active' => 'Espera un momento antes de volver a intentar.',
            'context_expired', 'context_unavailable' => 'El contexto de recuperación ya no está disponible.',
            'status_blocks_recovery' => 'Tu verificación aún se está confirmando. Actualiza el estado antes de reintentar.',
            default => 'No es posible iniciar la recuperación en este momento.',
        };
    }

    private function contextTypeValue(PaymentAuthenticationRecoveryContext $context): string
    {
        return $context->context_type instanceof \App\Enums\PaymentAuthenticationRecoveryContextType
            ? $context->context_type->value
            : (string) $context->context_type;
    }
}
