<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Database\Eloquent\Builder;

class PaymentAuthenticationRecoveryQuery
{
    public function filteredContextQuery(array $filters, PaymentAuthenticationAttemptDateRange $range): Builder
    {
        $query = PaymentAuthenticationRecoveryContext::query()
            ->whereBetween('started_at', [$range->from, $range->to]);

        $this->applyContextFilters($query, $filters);
        $this->applyAttemptScopedFilters($query, $filters);

        return $query;
    }

    public function applyRecoveryFiltersToAttempts(Builder $query, array $filters): void
    {
        if ($this->flag($filters, 'legacy_only')) {
            $query->whereNull('recovery_context_id');

            return;
        }

        if ($this->flag($filters, 'exclude_legacy')) {
            $query->whereNotNull('recovery_context_id');
        }

        $hasRecoveryFilter = collect([
            'recovery_context_type',
            'recovery_context_status',
            'recovery_method',
            'recovery_eligible',
            'recovery_started',
            'authentication_recovered',
            'payment_recovered',
            'selected_retry',
            'selected_different_card',
            'selected_paypal',
            'recovery_confirmation_pending',
            'limit_reached',
        ])->contains(fn (string $key) => array_key_exists($key, $filters) && $filters[$key] !== '' && $filters[$key] !== null);

        if (! $hasRecoveryFilter) {
            return;
        }

        $query->whereHas('recoveryContext', function (Builder $contextQuery) use ($filters) {
            $this->applyContextFilters($contextQuery, $filters);
        });

        $this->applyAttemptEventExistsFilters($query, $filters);
    }

    private function applyContextFilters(Builder $query, array $filters): void
    {
        $query->when($filters['recovery_context_type'] ?? null, function (Builder $q, string $type) {
            if ($type === PaymentAuthenticationRecoveryContextType::UNKNOWN) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where('context_type', $type);
            }
        });

        $query->when($filters['recovery_context_status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
        $query->when($filters['recovery_method'] ?? null, fn (Builder $q, string $method) => $q->where('recovery_method', $method));
        $query->when($filters['customer_id'] ?? null, fn (Builder $q, $customerId) => $q->where('customer_id', (int) $customerId));

        if ($this->flag($filters, 'recovery_eligible')) {
            $query->whereHas('rootAuthenticationAttempt', fn (Builder $root) => $root->whereIn(
                'status',
                PaymentAuthenticationAttemptStatus::recoverableTerminalValues()
            ));
        }

        if ($this->flag($filters, 'recovery_started')) {
            $query->where(function (Builder $inner) {
                $inner->whereNotIn('status', [
                    PaymentAuthenticationRecoveryContextStatus::Open->value,
                    PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
                ])->orWhereHas('authenticationAttempts.events', function (Builder $events) {
                    $events->whereIn('event_type', [
                        PaymentAuthenticationAttemptEventType::RecoveryStarted->value,
                        PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
                    ]);
                });
            });
        }

        if ($this->flag($filters, 'authentication_recovered')) {
            $query->where(function (Builder $inner) {
                $inner->where('status', PaymentAuthenticationRecoveryContextStatus::CardVerified->value)
                    ->orWhereNotNull('card_verified_at');
            })->whereHas('authenticationAttempts', function (Builder $attempts) {
                $attempts->where('status', PaymentAuthenticationAttemptStatus::Completed->value)
                    ->whereColumn('payment_authentication_attempts.id', '!=', 'payment_authentication_recovery_contexts.root_authentication_attempt_id');
            });
        }

        if ($this->flag($filters, 'payment_recovered')) {
            $query->where('status', PaymentAuthenticationRecoveryContextStatus::Recovered->value)
                ->whereNotNull('recovered_at')
                ->whereNotNull('recovered_transaction_id')
                ->whereHas('recoveredTransaction', function (Builder $tx) {
                    $tx->where('payment_status', 'captured')
                        ->where(function (Builder $outcome) {
                            $outcome->whereHas('laboratoryPurchases')
                                ->orWhereHas('medicalAttentionSubscriptions');
                        });
                });
        }

        if ($this->flag($filters, 'recovery_confirmation_pending')) {
            $query->where(function (Builder $inner) {
                $inner->whereHas('authenticationAttempts.events', fn (Builder $events) => $events->where(
                    'event_type',
                    PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value
                ))->orWhereHas('recoveryTransaction', function (Builder $tx) {
                    $tx->where('payment_status', 'pending')
                        ->where('details->recovery_confirmation_pending', true);
                });
            });
        }
    }

    private function applyAttemptEventExistsFilters(Builder $query, array $filters): void
    {
        if ($this->flag($filters, 'selected_retry')) {
            $query->whereHas('events', fn (Builder $events) => $events->where(
                'event_type',
                PaymentAuthenticationAttemptEventType::RecoveryStarted->value
            )->where('metadata->recovery_action', PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_RETRY));
        }

        if ($this->flag($filters, 'selected_different_card')) {
            $query->where(function (Builder $inner) {
                $inner->whereHas('events', fn (Builder $events) => $events->where(
                    'event_type',
                    PaymentAuthenticationAttemptEventType::ChangedCard->value
                ))->orWhereHas('events', fn (Builder $events) => $events->where(
                    'event_type',
                    PaymentAuthenticationAttemptEventType::RecoveryStarted->value
                )->where('metadata->recovery_action', PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_DIFFERENT_CARD));
            });
        }

        if ($this->flag($filters, 'selected_paypal')) {
            $query->whereHas('events', fn (Builder $events) => $events->where(
                'event_type',
                PaymentAuthenticationAttemptEventType::ChangedToPaypal->value
            ));
        }

        if ($this->flag($filters, 'limit_reached')) {
            $query->whereHas('events', fn (Builder $events) => $events->where(
                'event_type',
                PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value
            ));
        }
    }

    private function applyAttemptScopedFilters(Builder $query, array $filters): void
    {
        if (! array_key_exists('status', $filters) && ! array_key_exists('result_category', $filters)) {
            return;
        }

        $query->whereHas('rootAuthenticationAttempt', function (Builder $root) use ($filters) {
            if ($filters['status'] ?? null) {
                $root->where('status', $filters['status']);
            }

            if ($filters['result_category'] ?? null) {
                $root->where('failure_category', $filters['result_category']);
            }
        });
    }

    private function flag(array $filters, string $key): bool
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) {
            return false;
        }

        return filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
