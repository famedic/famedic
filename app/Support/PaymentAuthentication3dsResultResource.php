<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;

class PaymentAuthentication3dsResultResource
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextResource $recoveryContextResource,
        private PaymentAuthenticationRecoveryPolicy $policy,
        private PaymentAuthenticationRecoveryPayPalPolicy $paypalPolicy,
        private PaymentAuthenticationRecoveryReturnBuilder $returnBuilder
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(
        Efevoo3dsSession $session,
        Customer $customer,
        ?PaymentAuthenticationAttempt $attempt = null,
        ?PaymentAuthenticationRecoveryContext $recoveryContext = null
    ): array {
        $attempt ??= $session->paymentAuthenticationAttempt;
        $recoveryContext ??= $attempt?->recoveryContext;

        $attemptStatus = PaymentAuthenticationAttemptStatus::tryFrom((string) ($attempt?->status ?? ''));
        $presentation = $this->presentationState($attemptStatus, $recoveryContext, $session->status);
        $recovery = $this->recoveryPayload($customer, $attempt, $recoveryContext, $presentation);
        $copy = $this->copy($presentation, $recoveryContext, $customer, $recovery, $attemptStatus, $attempt);
        $verification = $this->verificationCharge($session, $attempt);

        return [
            'attempt_uuid' => $attempt?->attempt_uuid,
            'support_reference' => $attempt?->support_reference,
            'status' => $attempt?->status ?? $session->status,
            'session_status' => $session->status,
            'result_category' => $attempt?->failure_category,
            'failure_origin' => $attempt?->failure_origin,
            'failure_certainty' => $attempt?->failure_certainty,
            'provider_message' => EfevooPayLogSanitizer::providerMessage($attempt?->provider_message ?? $session->error_message),
            'started_at' => $attempt?->started_at?->toISOString(),
            'finished_at' => $attempt?->finished_at?->toISOString(),
            'expires_at' => $attempt?->expires_at?->toISOString(),
            'attempt_number' => $attempt?->attempt_number ?? 1,
            'maximum_attempts' => $this->policy->maxAttemptsPerContext(),
            'attempts_remaining' => $recoveryContext
                ? $this->policy->attemptsRemaining($recoveryContext)
                : 0,
            'cooldown_remaining_seconds' => $attempt
                ? $this->policy->cooldownRemainingSeconds($attempt)
                : 0,
            'presentation' => $presentation,
            'copy' => $copy,
            'verification_charge' => $verification,
            'card_last_four' => $this->safeCardLastFour($session),
            'recovery' => $recovery,
            'support' => [
                'reference' => $attempt?->support_reference,
                'email' => 'soporte@famedic.com',
                'channel_label' => 'soporte@famedic.com',
            ],
            'status_refresh_url' => route('payment-methods.3ds-result-status', ['sessionId' => $session->id]),
            'status_sync_url' => route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]),
            'active_attempt' => $this->activeAttemptPayload($customer),
            'completed' => $presentation === 'completed',
            'success' => $presentation === 'completed',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recoveryPayload(
        Customer $customer,
        ?PaymentAuthenticationAttempt $attempt,
        ?PaymentAuthenticationRecoveryContext $recoveryContext,
        string $presentation
    ): ?array {
        if (! $recoveryContext) {
            return null;
        }

        $contextResource = $this->recoveryContextResource->make($recoveryContext, $customer, $attempt);
        $evaluation = $attempt
            ? $this->policy->evaluate($customer, $attempt, $recoveryContext, PaymentAuthenticationRecoveryPolicy::ACTION_RETRY)
            : [
                'allowed' => false,
                'block_reason' => 'attempt_not_recoverable',
                'attempts_remaining' => $this->policy->attemptsRemaining($recoveryContext),
                'cooldown_remaining_seconds' => 0,
                'active_attempt' => $this->policy->activeAttempt($customer),
            ];

        $blockedPresentations = [
            'unknown',
            'provider_confirmation_pending',
            'authenticated',
            'tokenizing',
            'completed',
            'context_unavailable',
        ];

        $actionsBlocked = in_array($presentation, $blockedPresentations, true)
            || ! ($evaluation['allowed'] ?? false);

        $blockReason = in_array($presentation, $blockedPresentations, true)
            ? ($presentation === 'context_unavailable' ? 'context_expired' : 'status_blocks_recovery')
            : ($evaluation['block_reason'] ?? null);

        $paypalEvaluation = ($attempt && $recoveryContext && ! in_array($presentation, $blockedPresentations, true))
            ? $this->paypalPolicy->evaluate($customer, $recoveryContext, $attempt, $presentation)
            : ['allowed' => false, 'block_reason' => 'status_blocks_recovery', 'checkout_ready' => false];

        $supportsPaypal = (bool) ($paypalEvaluation['allowed'] ?? false)
            && (bool) config('services.paypal.client_id');

        return array_merge($contextResource, [
            'attempts_remaining' => $evaluation['attempts_remaining'] ?? $this->policy->attemptsRemaining($recoveryContext),
            'maximum_attempts' => $this->policy->maxAttemptsPerContext(),
            'cooldown_remaining_seconds' => $evaluation['cooldown_remaining_seconds'] ?? 0,
            'prioritize_different_card' => $this->policy->shouldPrioritizeDifferentCard($recoveryContext),
            'supports_paypal_future' => (bool) ($contextResource['supports_paypal'] ?? false),
            'supports_paypal' => $supportsPaypal,
            'actions' => [
                'retry' => ! $actionsBlocked && ($contextResource['supports_retry'] ?? false),
                'different_card' => ! $actionsBlocked && ($contextResource['supports_another_card'] ?? false),
                'paypal' => $supportsPaypal,
                'refresh_status' => in_array($presentation, ['unknown', 'provider_confirmation_pending', 'authenticated', 'tokenizing'], true),
                'safe_return' => true,
            ],
            'block_reason' => $blockReason,
            'recovery_start_url' => route('payment-methods.recovery.start'),
            'recovery_paypal_start_url' => route('payment-methods.recovery.paypal.start'),
            'recovery_paypal_cancel_url' => route('payment-methods.recovery.paypal.cancel'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $recovery
     * @return array{title: string, message: string, hint: string|null}
     */
    private function copy(
        string $presentation,
        ?PaymentAuthenticationRecoveryContext $recoveryContext,
        Customer $customer,
        ?array $recovery,
        ?PaymentAuthenticationAttemptStatus $attemptStatus,
        ?PaymentAuthenticationAttempt $attempt
    ): array {
        if ($presentation === 'context_unavailable') {
            return [
                'title' => 'Tu sesión de verificación expiró',
                'message' => 'El contexto de recuperación ya no está disponible. Regresa al flujo principal para continuar.',
                'hint' => null,
            ];
        }

        $type = $recoveryContext?->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $recoveryContext->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) ($recoveryContext?->context_type ?? ''));

        $hasSavedCart = $recovery['has_saved_cart'] ?? false;

        $title = match ($presentation) {
            'declined' => 'No pudimos completar la verificación',
            'cancelled' => 'La verificación no se completó',
            'expired' => 'La verificación expiró',
            'technical_error' => 'Tuvimos un problema al verificar tu tarjeta',
            'unknown', 'provider_confirmation_pending' => 'Estamos confirmando tu verificación',
            'authenticated', 'tokenizing' => 'Estamos guardando tu tarjeta',
            'completed' => 'Tarjeta verificada correctamente',
            default => 'Verificación en proceso',
        };

        $contextMessage = $this->contextualMessage($type, $hasSavedCart, $presentation, $attemptStatus, $attempt);

        return [
            'title' => $title,
            'message' => $contextMessage,
            'hint' => match ($presentation) {
                'cancelled' => 'Es posible que hayas cancelado el proceso o que se haya interrumpido antes de finalizar.',
                'expired' => 'Debes iniciar una nueva verificación; no reutilizaremos la sesión anterior.',
                'technical_error' => ($recovery['prioritize_different_card'] ?? false)
                    ? 'Si el problema continúa, intenta con otra tarjeta.'
                    : 'Si el problema continúa, vuelve a intentarlo o usa otra tarjeta.',
                'unknown', 'provider_confirmation_pending' => 'Actualiza el estado para consultar la respuesta definitiva.',
                default => null,
            },
        ];
    }

    private function contextualMessage(
        ?PaymentAuthenticationRecoveryContextType $type,
        bool $hasSavedCart,
        string $presentation,
        ?PaymentAuthenticationAttemptStatus $attemptStatus,
        ?PaymentAuthenticationAttempt $attempt
    ): string {
        if ($presentation === 'completed') {
            return 'Tu tarjeta fue verificada correctamente y ahora está lista para usarse.';
        }

        if (in_array($presentation, ['unknown', 'provider_confirmation_pending', 'authenticated', 'tokenizing'], true)) {
            return match ($type) {
                PaymentAuthenticationRecoveryContextType::PaymentMethodSettings => 'Estamos confirmando la verificación de tu método de pago.',
                PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
                PaymentAuthenticationRecoveryContextType::MedicalAttentionModal => 'Estamos confirmando la verificación de tu método de pago.',
                PaymentAuthenticationRecoveryContextType::LaboratoryCheckout => 'Estamos confirmando la verificación antes de continuar con tu pedido.',
                PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout => 'Estamos confirmando la verificación antes de continuar con tu pedido.',
                default => 'Estamos confirmando tu verificación con el proveedor de pagos.',
            };
        }

        $issuerConfirmed = $attempt?->failure_origin === EfevooPay3dsResultClassifier::ORIGIN_ISSUER
            && $attempt?->failure_certainty === EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED;

        $declinedDetail = $issuerConfirmed
            ? 'Tu banco no autorizó la verificación.'
            : 'No pudimos completar la verificación de tu tarjeta.';

        return match ($type) {
            PaymentAuthenticationRecoveryContextType::PaymentMethodSettings => 'No hay una compra pendiente asociada a esta verificación. Puedes intentar nuevamente o utilizar otra tarjeta.',
            PaymentAuthenticationRecoveryContextType::LaboratoryCheckout => $hasSavedCart
                ? 'Tu carrito sigue guardado y no se completó el pago.'
                : 'No se completó el pago. Puedes regresar al catálogo o al checkout para continuar.',
            PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
            PaymentAuthenticationRecoveryContextType::MedicalAttentionModal => 'No se completó la verificación de tu método de pago.',
            PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout => $hasSavedCart
                ? 'Tu carrito sigue guardado y no se completó el pago.'
                : 'No se completó el pago. Puedes regresar al catálogo o al checkout para continuar.',
            default => $presentation === 'declined' ? $declinedDetail : 'No se completó la verificación de tu tarjeta.',
        };
    }

    /**
     * @return array{amount: string|null, currency: string|null, message: string}
     */
    private function verificationCharge(Efevoo3dsSession $session, ?PaymentAuthenticationAttempt $attempt): array
    {
        $configuredAmount = number_format(config('efevoopay.test_amounts.default') / 100, 2, '.', '');
        $sessionAmount = is_numeric($session->amount) ? number_format((float) $session->amount, 2, '.', '') : null;
        $amount = $sessionAmount ?: $configuredAmount;

        if ($amount && $this->amountIsTrusted($amount)) {
            return [
                'amount' => $amount,
                'currency' => 'MXN',
                'message' => "Puede aparecer una verificación temporal de {$amount} MXN. Si permanece reflejada, comunícate con soporte.",
            ];
        }

        return [
            'amount' => null,
            'currency' => null,
            'message' => 'Puede aparecer una verificación temporal de seguridad. Si permanece reflejada, comunícate con soporte.',
        ];
    }

    private function amountIsTrusted(string $amount): bool
    {
        $configured = number_format(config('efevoopay.test_amounts.default') / 100, 2, '.', '');

        return $amount === $configured;
    }

    private function safeCardLastFour(Efevoo3dsSession $session): ?string
    {
        $lastFour = $session->card_last_four;

        if (! is_string($lastFour) || ! preg_match('/^\d{4}$/', $lastFour)) {
            return null;
        }

        return $lastFour;
    }

    private function presentationState(
        ?PaymentAuthenticationAttemptStatus $attemptStatus,
        ?PaymentAuthenticationRecoveryContext $recoveryContext,
        string $sessionStatus
    ): string {
        if ($recoveryContext) {
            if ($recoveryContext->isExpired() || $recoveryContext->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
                return 'context_unavailable';
            }

            if (in_array($recoveryContext->status, [
                PaymentAuthenticationRecoveryContextStatus::Cancelled,
                PaymentAuthenticationRecoveryContextStatus::Recovered,
            ], true)) {
                return 'context_unavailable';
            }
        }

        if ($attemptStatus) {
            return match ($attemptStatus) {
                PaymentAuthenticationAttemptStatus::Declined => 'declined',
                PaymentAuthenticationAttemptStatus::Cancelled => 'cancelled',
                PaymentAuthenticationAttemptStatus::Expired => 'expired',
                PaymentAuthenticationAttemptStatus::TechnicalError => 'technical_error',
                PaymentAuthenticationAttemptStatus::Unknown => 'unknown',
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending => 'provider_confirmation_pending',
                PaymentAuthenticationAttemptStatus::Authenticated => 'authenticated',
                PaymentAuthenticationAttemptStatus::Tokenizing => 'tokenizing',
                PaymentAuthenticationAttemptStatus::Completed => 'completed',
                default => 'processing',
            };
        }

        return match ($sessionStatus) {
            'declined' => 'declined',
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            'tokenization_failed', 'error', 'failed' => 'technical_error',
            default => 'processing',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeAttemptPayload(Customer $customer): ?array
    {
        $active = $this->policy->activeAttempt($customer);

        if (! $active) {
            return null;
        }

        $session = $active->efevoo3dsSession;

        return [
            'attempt_uuid' => $active->attempt_uuid,
            'support_reference' => $active->support_reference,
            'status' => $active->status,
            'result_url' => $session
                ? route('payment-methods.3ds-result', ['sessionId' => $session->id])
                : null,
            'redirect_url' => $session
                ? route('payment-methods.3ds-redirect', ['sessionId' => $session->id])
                : null,
        ];
    }
}
