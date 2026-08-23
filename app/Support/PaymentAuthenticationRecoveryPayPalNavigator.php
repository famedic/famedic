<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class PaymentAuthenticationRecoveryPayPalNavigator
{
    public function __construct(
        private PaymentAuthenticationRecoveryPayPalPolicy $policy,
        private PaymentAuthenticationRecoveryContextGuard $guard,
        private PaymentAuthenticationRecoveryContextManager $contextManager,
        private PaymentAuthenticationRecoveryReturnBuilder $returnBuilder,
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore
    ) {}

    /**
     * @return array{redirect_url: string, recovery_context_uuid: string, checkout_action: array<string, mixed>}
     */
    public function start(
        Customer $customer,
        Efevoo3dsSession $session,
        PaymentAuthenticationRecoveryContext $context
    ): array {
        $attempt = $session->paymentAuthenticationAttempt;

        if (! $attempt || (int) $attempt->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $this->guard->requireOwned($customer, $context);
        $this->contextManager->expireIfNeeded($context);
        $context = $context->fresh() ?? $context;

        $evaluation = $this->policy->evaluate($customer, $context, $attempt->fresh());

        if (! $evaluation['allowed']) {
            $this->recordBlocked($attempt, $context, $evaluation['block_reason'] ?? 'unknown');

            throw PaymentAuthenticationRecoveryStartException::blocked(
                $this->blockedMessage($evaluation['block_reason'] ?? 'unknown'),
                $evaluation['block_reason'] ?? 'unknown',
                $evaluation
            );
        }

        $this->cardDataStore->purgeByAttempt($attempt, 'changed_to_paypal', [
            'stage' => 'paypal_recovery_start',
            'detected_by' => 'famedic',
        ]);
        $this->cardDataStore->purgeLegacyGlobal();

        if (! $this->cardDataStore->assertAbsent((int) $session->id)) {
            Log::warning('[3DS] Sensitive card data still present after PayPal purge', [
                'session_id' => $session->id,
                'attempt_id' => $attempt->id,
            ]);
        }

        DB::transaction(function () use ($context) {
            $locked = \App\Models\PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable) {
                $locked->forceFill([
                    'recovery_method' => 'paypal',
                ])->save();
            }
        });

        $metadata = [
            'context_uuid' => $context->context_uuid,
            'context_type' => $this->typeValue($context),
            'detected_by' => 'paypal_recovery_navigation',
        ];

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChangedToPaypal, [
            'source' => 'frontend',
            'dedupe_key' => 'changed_to_paypal:'.$attempt->id,
            'metadata' => $metadata,
        ]);

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryPaymentStarted, [
            'source' => 'frontend',
            'dedupe_key' => 'recovery_payment_started:'.$attempt->id.':paypal',
            'metadata' => array_merge($metadata, [
                'recovery_action' => 'paypal',
                'previous_attempt_id' => $attempt->id,
            ]),
        ]);

        Session::put($this->sessionKey($customer), [
            'recovery_context_uuid' => $context->context_uuid,
            'failed_attempt_id' => $attempt->id,
            'payment_method' => 'paypal',
            'expires_at' => now()->addMinutes(max(1, (int) config('efevoopay.recovery.navigation_session_ttl_minutes', 10)))->timestamp,
        ]);

        $checkoutAction = $this->returnBuilder->action($customer, $context->fresh());
        $redirectUrl = $checkoutAction['href'].'&'.http_build_query([
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_payment' => 'paypal',
            'step' => 'payment',
        ]);

        return [
            'redirect_url' => $redirectUrl,
            'recovery_context_uuid' => $context->context_uuid,
            'checkout_action' => $checkoutAction,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumePreparedCheckout(Customer $customer): ?array
    {
        $payload = Session::get($this->sessionKey($customer));

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            Session::forget($this->sessionKey($customer));

            return null;
        }

        return $payload;
    }

    public function clearPreparedCheckout(Customer $customer): void
    {
        Session::forget($this->sessionKey($customer));
    }

    public function sessionKey(Customer $customer): string
    {
        return 'payment_auth_recovery_paypal_'.$customer->id;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function recordBlocked(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationRecoveryContext $context,
        string $blockReason
    ): void {
        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryRetryBlocked, [
            'source' => 'frontend',
            'dedupe_key' => 'recovery_paypal_blocked:'.$attempt->id.':'.$blockReason,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'recovery_action' => 'paypal',
                'block_reason' => $blockReason,
                'detected_by' => 'paypal_recovery_navigation',
            ],
        ]);
    }

    private function blockedMessage(?string $blockReason): string
    {
        return match ($blockReason) {
            'active_attempt_exists' => 'Ya tienes una verificación en proceso.',
            'recovery_confirmation_pending' => 'Estamos confirmando tu pago con PayPal. Actualiza el estado o contacta soporte.',
            'purchase_already_completed' => 'Tu compra ya fue completada.',
            'context_expired', 'context_unavailable' => 'El contexto de recuperación ya no está disponible.',
            'status_blocks_recovery' => 'Tu verificación aún se está confirmando.',
            'context_type_not_supported' => 'PayPal no está disponible para este flujo.',
            default => 'No es posible continuar con PayPal en este momento.',
        };
    }

    private function typeValue(PaymentAuthenticationRecoveryContext $context): string
    {
        return $context->context_type instanceof \App\Enums\PaymentAuthenticationRecoveryContextType
            ? $context->context_type->value
            : (string) $context->context_type;
    }
}
