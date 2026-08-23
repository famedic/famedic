<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\PaymentAuthenticationRecoveryContext;

class PaymentAuthenticationRecoveryMetrics
{
    public function __construct(
        private PaymentAuthenticationRecoveryQuery $recoveryQuery,
        private PaymentAuthenticationAttemptQuery $attemptQuery,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(array $filters, PaymentAuthenticationAttemptDateRange $range): array
    {
        $base = $this->recoveryQuery->filteredContextQuery($filters, $range);

        $legacyAttempts = (int) $this->attemptQuery
            ->filteredQuery($filters, $range)
            ->whereNull('recovery_context_id')
            ->count();

        if (! (clone $base)->toBase()->selectRaw('1')->limit(1)->exists()) {
            return array_merge($this->emptyMetrics(), [
                'legacy_attempts_without_context' => $legacyAttempts,
            ]);
        }

        $eligibleQuery = (clone $base)->whereHas('rootAuthenticationAttempt', fn ($root) => $root->whereIn(
            'status',
            \App\Enums\PaymentAuthenticationAttemptStatus::recoverableTerminalValues()
        ));

        $eligibleCheckoutQuery = (clone $eligibleQuery)->whereIn('context_type', [
            \App\Enums\PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->value,
            \App\Enums\PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout->value,
        ]);

        $paypalEligibleQuery = (clone $eligibleCheckoutQuery)->whereIn('context_type', [
            \App\Enums\PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->value,
            \App\Enums\PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout->value,
        ]);

        $eligible = (int) (clone $eligibleQuery)->count('payment_authentication_recovery_contexts.id');
        $eligibleCheckout = (int) (clone $eligibleCheckoutQuery)->count('payment_authentication_recovery_contexts.id');

        $started = (int) (clone $eligibleQuery)->where(function ($q) {
            $q->whereNotIn('status', [
                PaymentAuthenticationRecoveryContextStatus::Open->value,
                PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
            ])->orWhereHas('authenticationAttempts.events', fn ($events) => $events->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::RecoveryStarted->value,
                PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
            ]));
        })->count('payment_authentication_recovery_contexts.id');

        $authenticationRecovered = (int) (clone $eligibleQuery)
            ->where(function ($q) {
                $q->where('status', PaymentAuthenticationRecoveryContextStatus::CardVerified->value)
                    ->orWhereNotNull('card_verified_at');
            })
            ->whereHas('authenticationAttempts', function ($attempts) {
                $attempts->where('status', \App\Enums\PaymentAuthenticationAttemptStatus::Completed->value)
                    ->whereColumn(
                        'payment_authentication_attempts.id',
                        '!=',
                        'payment_authentication_recovery_contexts.root_authentication_attempt_id'
                    );
            })
            ->count('payment_authentication_recovery_contexts.id');

        $paymentRecovered = (int) (clone $eligibleCheckoutQuery)
            ->where('status', PaymentAuthenticationRecoveryContextStatus::Recovered->value)
            ->whereNotNull('recovered_at')
            ->whereNotNull('recovered_transaction_id')
            ->whereHas('recoveredTransaction', function ($tx) {
                $tx->where('payment_status', 'captured')
                    ->where(function ($outcome) {
                        $outcome->whereHas('laboratoryPurchases')
                            ->orWhereHas('medicalAttentionSubscriptions');
                    });
            })
            ->count('payment_authentication_recovery_contexts.id');

        $paypalRecovered = (int) (clone $eligibleCheckoutQuery)
            ->where('status', PaymentAuthenticationRecoveryContextStatus::Recovered->value)
            ->where('recovery_method', 'paypal')
            ->whereNotNull('recovered_at')
            ->whereHas('recoveredTransaction', function ($tx) {
                $tx->where('payment_status', 'captured')
                    ->where(function ($outcome) {
                        $outcome->whereHas('laboratoryPurchases')
                            ->orWhereHas('medicalAttentionSubscriptions');
                    });
            })
            ->count('payment_authentication_recovery_contexts.id');

        $recoveryAvailableIdle = (int) (clone $eligibleQuery)
            ->where('status', PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value)
            ->whereDoesntHave('authenticationAttempts.events', fn ($events) => $events->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::RecoveryStarted->value,
                PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
            ]))
            ->count('payment_authentication_recovery_contexts.id');

        $paymentInProgress = (int) (clone $base)
            ->where('status', PaymentAuthenticationRecoveryContextStatus::PaymentInProgress->value)
            ->count('payment_authentication_recovery_contexts.id');

        $confirmationPending = (int) (clone $base)->where(function ($q) {
            $q->whereHas('authenticationAttempts.events', fn ($events) => $events->where(
                'event_type',
                PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value
            ))->orWhereHas('recoveryTransaction', fn ($tx) => $tx->where('details->recovery_confirmation_pending', true));
        })->count('payment_authentication_recovery_contexts.id');

        $expired = (int) (clone $base)
            ->where('status', PaymentAuthenticationRecoveryContextStatus::Expired->value)
            ->count('payment_authentication_recovery_contexts.id');

        $limitReached = (int) (clone $base)->whereHas('authenticationAttempts.events', fn ($events) => $events->where(
            'event_type',
            PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value
        ))->count('payment_authentication_recovery_contexts.id');

        $avgAttempts = (clone $base)
            ->withCount('authenticationAttempts')
            ->get()
            ->avg('authentication_attempts_count');

        $avgAuthSeconds = $this->averageSeconds(
            (clone $eligibleQuery)->whereNotNull('card_verified_at')->get(['started_at', 'card_verified_at'])
        );

        $avgPaymentSeconds = $this->averageSeconds(
            (clone $eligibleCheckoutQuery)->whereNotNull('recovered_at')->get(['started_at', 'recovered_at']),
            'recovered_at'
        );

        $legacyAttempts = (int) $this->attemptQuery
            ->filteredQuery($filters, $range)
            ->whereNull('recovery_context_id')
            ->count();

        $rate = fn (int $numerator, int $denominator): ?float => $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : null;

        return [
            'eligible_terminal' => $eligible,
            'eligible_terminal_denominator_label' => 'Contextos con fallo terminal recuperable en el periodo',
            'eligible_checkout' => $eligibleCheckout,
            'eligible_checkout_denominator_label' => 'Contextos de checkout elegibles en el periodo',
            'recovery_started' => $started,
            'authentication_recovered' => $authenticationRecovered,
            'payment_recovered' => $paymentRecovered,
            'paypal_recovered' => $paypalRecovered,
            'recovery_available_idle' => $recoveryAvailableIdle,
            'payment_in_progress' => $paymentInProgress,
            'confirmation_pending' => $confirmationPending,
            'expired_contexts' => $expired,
            'limit_reached' => $limitReached,
            'average_attempts_per_context' => $avgAttempts !== null ? round((float) $avgAttempts, 1) : null,
            'average_seconds_to_authentication_recovery' => $avgAuthSeconds,
            'average_seconds_to_payment_recovery' => $avgPaymentSeconds,
            'recovery_start_rate' => $rate($started, $eligible),
            'authentication_recovery_rate' => $rate($authenticationRecovered, $eligible),
            'payment_recovery_rate' => $rate($paymentRecovered, $eligibleCheckout),
            'paypal_recovery_rate' => $rate($paypalRecovered, $eligibleCheckout),
            'legacy_attempts_without_context' => $legacyAttempts,
            'funnel' => [
                'eligible' => $eligible,
                'started' => $started,
                'branches' => [
                    'card_verified' => $authenticationRecovered,
                    'paypal_paid' => $paypalRecovered,
                ],
                'note' => 'Las ramas de tarjeta y PayPal son alternativas; un contexto no debería contarse en ambas.',
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaymentAuthenticationRecoveryContext>  $rows
     */
    private function averageSeconds($rows, string $endColumn = 'card_verified_at'): ?int
    {
        $values = $rows
            ->filter(fn ($row) => $row->started_at && $row->{$endColumn})
            ->map(fn ($row) => (int) $row->started_at->diffInSeconds($row->{$endColumn}))
            ->filter(fn ($seconds) => $seconds >= 0);

        if ($values->isEmpty()) {
            return null;
        }

        return (int) round($values->avg());
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(): array
    {
        return [
            'eligible_terminal' => 0,
            'eligible_terminal_denominator_label' => 'Contextos con fallo terminal recuperable en el periodo',
            'eligible_checkout' => 0,
            'eligible_checkout_denominator_label' => 'Contextos de checkout elegibles en el periodo',
            'recovery_started' => 0,
            'authentication_recovered' => 0,
            'payment_recovered' => 0,
            'paypal_recovered' => 0,
            'recovery_available_idle' => 0,
            'payment_in_progress' => 0,
            'confirmation_pending' => 0,
            'expired_contexts' => 0,
            'limit_reached' => 0,
            'average_attempts_per_context' => null,
            'average_seconds_to_authentication_recovery' => null,
            'average_seconds_to_payment_recovery' => null,
            'recovery_start_rate' => null,
            'authentication_recovery_rate' => null,
            'payment_recovery_rate' => null,
            'paypal_recovery_rate' => null,
            'funnel' => [
                'eligible' => 0,
                'started' => 0,
                'branches' => [
                    'card_verified' => 0,
                    'paypal_paid' => 0,
                ],
                'note' => 'Las ramas de tarjeta y PayPal son alternativas; un contexto no debería contarse en ambas.',
            ],
        ];
    }
}
