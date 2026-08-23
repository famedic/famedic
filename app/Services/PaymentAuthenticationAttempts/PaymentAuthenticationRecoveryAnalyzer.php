<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Support\Collection;

class PaymentAuthenticationRecoveryAnalyzer
{
    /**
     * @return list<string>
     */
    public static function intentionEventTypes(): array
    {
        return [
            PaymentAuthenticationAttemptEventType::RecoveryStarted->value,
            PaymentAuthenticationAttemptEventType::ChangedCard->value,
            PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
            PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value,
            PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value,
            PaymentAuthenticationAttemptEventType::RecoveryCompleted->value,
            PaymentAuthenticationAttemptEventType::CardVerified->value,
            PaymentAuthenticationAttemptEventType::PaypalCaptureSucceeded->value,
        ];
    }

    /**
     * @param  list<int>  $attemptIds
     * @return array<int, array<string, bool>>
     */
    public function batchIntentionFlags(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $events = PaymentAuthenticationAttemptEvent::query()
            ->whereIn('payment_authentication_attempt_id', $attemptIds)
            ->whereIn('event_type', self::intentionEventTypes())
            ->get(['payment_authentication_attempt_id', 'event_type', 'metadata']);

        $flags = [];

        foreach ($events as $event) {
            $attemptId = (int) $event->payment_authentication_attempt_id;
            $flags[$attemptId] ??= self::emptyIntentionFlags();

            match ($event->event_type) {
                PaymentAuthenticationAttemptEventType::RecoveryStarted->value => $this->applyRecoveryStartedFlag($flags[$attemptId], $event),
                PaymentAuthenticationAttemptEventType::ChangedCard->value => $flags[$attemptId]['selected_different_card'] = true,
                PaymentAuthenticationAttemptEventType::ChangedToPaypal->value => $flags[$attemptId]['selected_paypal'] = true,
                PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value => $flags[$attemptId]['limit_reached'] = true,
                PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value => $flags[$attemptId]['confirmation_pending'] = true,
                PaymentAuthenticationAttemptEventType::RecoveryCompleted->value => $flags[$attemptId]['payment_completed_event'] = true,
                PaymentAuthenticationAttemptEventType::CardVerified->value => $flags[$attemptId]['card_verified_event'] = true,
                PaymentAuthenticationAttemptEventType::PaypalCaptureSucceeded->value => $flags[$attemptId]['paypal_capture_event'] = true,
                default => null,
            };
        }

        return $flags;
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function applyRecoveryStartedFlag(array &$flags, PaymentAuthenticationAttemptEvent $event): void
    {
        $flags['recovery_started'] = true;
        $action = data_get($event->allowlistedMetadata(), 'recovery_action');

        if ($action === PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_DIFFERENT_CARD) {
            $flags['selected_different_card'] = true;
        } elseif ($action === PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_RETRY) {
            $flags['selected_retry'] = true;
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function emptyIntentionFlags(): array
    {
        return [
            'recovery_started' => false,
            'selected_retry' => false,
            'selected_different_card' => false,
            'selected_paypal' => false,
            'limit_reached' => false,
            'confirmation_pending' => false,
            'payment_completed_event' => false,
            'card_verified_event' => false,
            'paypal_capture_event' => false,
        ];
    }

    public function isEligibleTerminalContext(PaymentAuthenticationRecoveryContext $context): bool
    {
        $root = $context->rootAuthenticationAttempt;

        if (! $root) {
            return false;
        }

        return in_array(
            $root->status,
            PaymentAuthenticationAttemptStatus::recoverableTerminalValues(),
            true
        );
    }

    public function isCheckoutContext(PaymentAuthenticationRecoveryContext $context): bool
    {
        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        return in_array($type, [
            PaymentAuthenticationRecoveryContextType::LaboratoryCheckout,
            PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
        ], true);
    }

    public function supportsPayPal(PaymentAuthenticationRecoveryContext $context): bool
    {
        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        return (bool) $type?->supportsPayPal();
    }

    /**
     * @param  array<string, bool>  $flags
     */
    public function recoveryStarted(PaymentAuthenticationRecoveryContext $context, array $flags = []): bool
    {
        if ($flags['recovery_started'] ?? false) {
            return true;
        }

        if ($flags['selected_paypal'] ?? false) {
            return true;
        }

        return ! in_array($context->status, [
            PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
            PaymentAuthenticationRecoveryContextStatus::Open,
        ], true);
    }

    public function authenticationRecovered(PaymentAuthenticationRecoveryContext $context): bool
    {
        if ($context->status === PaymentAuthenticationRecoveryContextStatus::CardVerified
            || $context->card_verified_at !== null) {
            return $this->hasLaterCompletedAttempt($context);
        }

        return false;
    }

    public function paymentRecovered(PaymentAuthenticationRecoveryContext $context): bool
    {
        if ($context->status !== PaymentAuthenticationRecoveryContextStatus::Recovered
            || $context->recovered_at === null
            || $context->recovered_transaction_id === null) {
            return false;
        }

        $transaction = $context->relationLoaded('recoveredTransaction')
            ? $context->recoveredTransaction
            : Transaction::query()->find($context->recovered_transaction_id);

        if (! $transaction || ($transaction->payment_status ?? '') !== 'captured') {
            return false;
        }

        if ($this->isCheckoutContext($context)) {
            return $transaction->laboratoryPurchases()->exists()
                || $transaction->medicalAttentionSubscriptions()->exists();
        }

        return true;
    }

    public function hasLaterCompletedAttempt(PaymentAuthenticationRecoveryContext $context): bool
    {
        $rootId = (int) ($context->root_authentication_attempt_id ?? 0);

        if ($rootId <= 0) {
            return PaymentAuthenticationAttempt::query()
                ->where('recovery_context_id', $context->id)
                ->where('status', PaymentAuthenticationAttemptStatus::Completed->value)
                ->exists();
        }

        return PaymentAuthenticationAttempt::query()
            ->where('recovery_context_id', $context->id)
            ->where('id', '!=', $rootId)
            ->where('status', PaymentAuthenticationAttemptStatus::Completed->value)
            ->exists();
    }

    public function isConfirmationPending(PaymentAuthenticationRecoveryContext $context, array $flags = []): bool
    {
        if ($flags['confirmation_pending'] ?? false) {
            return true;
        }

        $pending = $context->recoveryTransaction;

        if (! $pending) {
            return false;
        }

        $details = is_array($pending->details) ? $pending->details : [];

        return (bool) ($details['recovery_confirmation_pending'] ?? false);
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, mixed>
     */
    public function summarizeForAttempt(
        PaymentAuthenticationAttempt $attempt,
        array $flags = [],
    ): array {
        $context = $attempt->recoveryContext;

        if (! $context) {
            return [
                'available' => false,
                'legacy' => true,
                'summary_label' => 'Legacy / contexto no disponible',
                'context_type' => PaymentAuthenticationRecoveryContextType::UNKNOWN,
                'context_status' => null,
                'selected_intention' => null,
                'chain_attempt_count' => null,
                'authentication_outcome' => null,
                'payment_outcome' => null,
                'confirmed_method' => null,
                'time_to_recovery_seconds' => null,
                'pending_blocked' => false,
            ];
        }

        $eligible = $this->isEligibleTerminalContext($context);
        $started = $this->recoveryStarted($context, $flags);
        $authRecovered = $this->authenticationRecovered($context);
        $paymentRecovered = $this->paymentRecovered($context);
        $confirmationPending = $this->isConfirmationPending($context, $flags);
        $limitReached = $flags['limit_reached'] ?? false;

        return [
            'available' => true,
            'legacy' => false,
            'summary_label' => $this->summaryLabel(
                $context,
                $flags,
                $eligible,
                $started,
                $authRecovered,
                $paymentRecovered,
                $confirmationPending,
                $limitReached,
            ),
            'context_uuid_masked' => $this->maskUuid($context->context_uuid),
            'context_type' => $context->context_type?->value ?? $context->context_type,
            'context_status' => $context->status?->value ?? $context->status,
            'selected_intention' => $this->selectedIntentionLabel($flags),
            'chain_attempt_count' => (int) ($context->authentication_attempts_count ?? $context->authenticationAttempts()->count()),
            'authentication_outcome' => $authRecovered ? 'Tarjeta verificada posteriormente' : ($paymentRecovered ? null : 'No comprobada'),
            'payment_outcome' => $paymentRecovered
                ? 'Pago recuperado'
                : ($confirmationPending ? 'Confirmación pendiente' : 'No comprobado'),
            'confirmed_method' => $paymentRecovered ? ($context->recovery_method ?? null) : null,
            'authentication_recovered' => $authRecovered,
            'payment_recovered' => $paymentRecovered,
            'recovery_started' => $started,
            'recovery_eligible' => $eligible,
            'time_to_recovery_seconds' => $this->timeToPaymentRecoverySeconds($context),
            'time_to_authentication_recovery_seconds' => $this->timeToAuthenticationRecoverySeconds($context),
            'pending_blocked' => $confirmationPending || $limitReached || $context->status === PaymentAuthenticationRecoveryContextStatus::PaymentInProgress,
            'confirmation_pending' => $confirmationPending,
            'limit_reached' => $limitReached,
            'card_verified_at_local' => PaymentAuthenticationRecoveryAnalyzer::formatLocalDate($context->card_verified_at),
            'recovered_at_local' => PaymentAuthenticationRecoveryAnalyzer::formatLocalDate($context->recovered_at),
            'recovery_transaction_id' => $context->recovery_transaction_id,
            'recovered_transaction_id' => $context->recovered_transaction_id,
        ];
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function summaryLabel(
        PaymentAuthenticationRecoveryContext $context,
        array $flags,
        bool $eligible,
        bool $started,
        bool $authRecovered,
        bool $paymentRecovered,
        bool $confirmationPending,
        bool $limitReached,
    ): string {
        if ($context->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
            return 'Contexto expirado';
        }

        if ($limitReached || ($flags['limit_reached'] ?? false)) {
            return 'Límite alcanzado';
        }

        if ($paymentRecovered) {
            return $context->recovery_method === 'paypal'
                ? 'Pago recuperado con PayPal'
                : 'Pago recuperado';
        }

        if ($confirmationPending) {
            return 'Confirmación pendiente';
        }

        if ($authRecovered) {
            return 'Tarjeta verificada';
        }

        if ($flags['selected_paypal'] ?? false) {
            return 'Seleccionó PayPal';
        }

        if ($flags['selected_different_card'] ?? false) {
            return 'Seleccionó otra tarjeta';
        }

        if ($flags['selected_retry'] ?? false) {
            return 'Reintentó verificación';
        }

        if ($started) {
            return 'Recuperación iniciada';
        }

        if ($eligible && $context->status === PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable) {
            return 'Disponible, sin iniciar';
        }

        if ($context->status === PaymentAuthenticationRecoveryContextStatus::PaymentInProgress) {
            return 'Pago en progreso';
        }

        return 'No disponible';
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function selectedIntentionLabel(array $flags): ?string
    {
        if ($flags['selected_paypal'] ?? false) {
            return 'Seleccionó PayPal';
        }

        if ($flags['selected_different_card'] ?? false) {
            return 'Seleccionó otra tarjeta';
        }

        if ($flags['selected_retry'] ?? false) {
            return 'Seleccionó volver a intentar';
        }

        return null;
    }

    public function maskUuid(?string $uuid): ?string
    {
        if (! is_string($uuid) || strlen($uuid) < 12) {
            return $uuid;
        }

        return substr($uuid, 0, 8).'…'.substr($uuid, -4);
    }

    public function timeToAuthenticationRecoverySeconds(PaymentAuthenticationRecoveryContext $context): ?int
    {
        if ($context->card_verified_at === null || $context->started_at === null) {
            return null;
        }

        return (int) $context->started_at->diffInSeconds($context->card_verified_at);
    }

    public function timeToPaymentRecoverySeconds(PaymentAuthenticationRecoveryContext $context): ?int
    {
        if ($context->recovered_at === null || $context->started_at === null) {
            return null;
        }

        return (int) $context->started_at->diffInSeconds($context->recovered_at);
    }

    public function detailRecoveryCard(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationRecoveryContext $context,
        Collection $events,
        int $chainAttemptCount,
    ): array {
        $allAttemptIds = PaymentAuthenticationAttempt::query()
            ->where('recovery_context_id', $context->id)
            ->pluck('id')
            ->all();

        $flags = self::emptyIntentionFlags();
        foreach ($this->batchIntentionFlags($allAttemptIds) as $attemptFlags) {
            foreach ($attemptFlags as $key => $value) {
                if ($value) {
                    $flags[$key] = true;
                }
            }
        }

        $context->loadMissing(['rootAuthenticationAttempt', 'recoveryTransaction', 'recoveredTransaction']);
        $attempt->setRelation('recoveryContext', $context);
        $summary = $this->summarizeForAttempt($attempt, $flags);
        $root = $context->rootAuthenticationAttempt;

        return array_merge($summary, [
            'context_uuid_masked' => $this->maskUuid($context->context_uuid),
            'started_at_local' => self::formatLocalDate($context->started_at),
            'expires_at_local' => self::formatLocalDate($context->expires_at),
            'card_verified_at_local' => self::formatLocalDate($context->card_verified_at),
            'recovered_at_local' => self::formatLocalDate($context->recovered_at),
            'root_attempt_id' => $root?->id,
            'root_support_reference' => $root?->support_reference,
            'recovery_transaction_id' => $context->recovery_transaction_id,
            'recovered_transaction_id' => $context->recovered_transaction_id,
            'chain_attempt_count' => $chainAttemptCount,
            'intention_vs_evidence' => [
                'selected_retry' => ($flags['selected_retry'] ?? false) ? 'Seleccionó volver a intentar' : null,
                'selected_different_card' => ($flags['selected_different_card'] ?? false) ? 'Seleccionó usar otra tarjeta' : null,
                'selected_paypal' => ($flags['selected_paypal'] ?? false) ? 'Seleccionó PayPal' : null,
                'authentication_recovered' => $this->authenticationRecovered($context) ? 'Tarjeta verificada posteriormente' : null,
                'payment_recovered' => $this->paymentRecovered($context) ? 'Pago PayPal comprobado' : null,
            ],
            'help' => 'Las acciones de tarjeta reflejan la opción seleccionada por el usuario; FAMEDIC no compara números de tarjeta para determinar si cambió físicamente la tarjeta.',
        ]);
    }

    public static function formatLocalDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date->copy()->timezone(PaymentAuthenticationAttemptDateRange::TIMEZONE)->isoFormat('D MMM Y, HH:mm:ss');
    }
}
