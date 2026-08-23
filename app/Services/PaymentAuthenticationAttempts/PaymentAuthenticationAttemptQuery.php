<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\PaymentAuthenticationAttempt;
use App\Support\EfevooPay3dsResultClassifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PaymentAuthenticationAttemptQuery
{
    public function __construct(
        private PaymentAuthenticationRecoveryQuery $recoveryQuery,
    ) {}

    public function filteredQuery(array $filters, PaymentAuthenticationAttemptDateRange $range): Builder
    {
        $query = PaymentAuthenticationAttempt::query()
            ->whereBetween('started_at', [$range->from, $range->to]);

        $this->applyColumnFilters($query, $filters);
        $this->recoveryQuery->applyRecoveryFiltersToAttempts($query, $filters);

        if ($this->flag($filters, 'recovered_chain')) {
            $rootIds = $this->recoveredRootIds((clone $query)->whereNull('retry_of_attempt_id'));

            $query->whereIn('id', $rootIds->isEmpty() ? [0] : $rootIds->all());
        }

        return $query;
    }

    public function paginate(array $filters, PaymentAuthenticationAttemptDateRange $range, int $perPage = 25): LengthAwarePaginator
    {
        return $this->filteredQuery($filters, $range)
            ->with(['customer.user', 'efevoo3dsSession', 'recoveryContext' => fn ($q) => $q->withCount('authenticationAttempts')])
            ->withCount('retryAttempts')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function exportRows(array $filters, PaymentAuthenticationAttemptDateRange $range, int $limit = 5000): Collection
    {
        return $this->filteredQuery($filters, $range)
            ->with(['customer.user', 'efevoo3dsSession', 'recoveryContext' => fn ($q) => $q->withCount('authenticationAttempts')])
            ->withCount('retryAttempts')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function recoveredRootIds(Builder $rootQuery): Collection
    {
        $rootIds = (clone $rootQuery)
            ->whereNull('retry_of_attempt_id')
            ->whereIn('status', PaymentAuthenticationAttemptStatus::recoverableTerminalValues())
            ->pluck('id');

        if ($rootIds->isEmpty()) {
            return collect();
        }

        $recovered = collect();
        $frontier = $rootIds;
        $parentToRoot = $rootIds->mapWithKeys(fn ($id) => [(int) $id => (int) $id]);

        while ($frontier->isNotEmpty()) {
            $children = PaymentAuthenticationAttempt::query()
                ->whereIn('retry_of_attempt_id', $frontier)
                ->get(['id', 'status', 'retry_of_attempt_id']);

            $next = collect();

            foreach ($children as $child) {
                $rootId = $parentToRoot[(int) $child->retry_of_attempt_id] ?? null;

                if (! $rootId) {
                    continue;
                }

                $parentToRoot[(int) $child->id] = $rootId;

                if ($child->status === PaymentAuthenticationAttemptStatus::Completed->value) {
                    $recovered->push($rootId);
                }

                $next->push($child->id);
            }

            $frontier = $next;
        }

        return $recovered->unique()->values();
    }

    public function chainFor(PaymentAuthenticationAttempt $attempt): Collection
    {
        $current = $attempt;
        $guard = 0;

        while ($current->retry_of_attempt_id && $guard < 20) {
            $previous = PaymentAuthenticationAttempt::query()
                ->whereKey($current->retry_of_attempt_id)
                ->where('customer_id', $attempt->customer_id)
                ->first();

            if (! $previous) {
                break;
            }

            $current = $previous;
            $guard++;
        }

        $chain = collect([$current]);
        $frontier = collect([$current->id]);
        $guard = 0;

        while ($frontier->isNotEmpty() && $guard < 20) {
            $children = PaymentAuthenticationAttempt::query()
                ->where('customer_id', $attempt->customer_id)
                ->whereIn('retry_of_attempt_id', $frontier)
                ->orderBy('attempt_number')
                ->orderBy('id')
                ->get();

            foreach ($children as $child) {
                $chain->push($child);
            }

            $frontier = $children->pluck('id');
            $guard++;
        }

        return $chain->unique('id')->values();
    }

    private function applyColumnFilters(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
        $query->when($filters['result_category'] ?? null, fn (Builder $q, string $category) => $q->where('failure_category', $category));
        $query->when($filters['failure_origin'] ?? null, fn (Builder $q, string $origin) => $q->where('failure_origin', $origin));
        $query->when($filters['failure_certainty'] ?? null, fn (Builder $q, string $certainty) => $q->where('failure_certainty', $certainty));
        $query->when($filters['provider'] ?? null, fn (Builder $q, string $provider) => $q->where('provider', $provider));
        $query->when($filters['attempt_uuid'] ?? null, fn (Builder $q, string $uuid) => $q->where('attempt_uuid', $uuid));
        $query->when($filters['support_reference'] ?? null, fn (Builder $q, string $reference) => $q->where('support_reference', $reference));
        $query->when($filters['merchant_reference'] ?? null, fn (Builder $q, string $reference) => $q->where('merchant_reference', $reference));
        $query->when($filters['provider_order_id'] ?? null, fn (Builder $q, string $orderId) => $q->where('provider_order_id', $orderId));
        $query->when($filters['customer_id'] ?? null, fn (Builder $q, $customerId) => $q->where('customer_id', (int) $customerId));

        $query->when($filters['customer'] ?? null, function (Builder $q, string $search) {
            $q->where(function (Builder $inner) use ($search) {
                $inner->whereHas('customer.user', function (Builder $userQuery) use ($search) {
                    $userQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('paternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('maternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', $search);
                });

                if (ctype_digit($search)) {
                    $inner->orWhere('customer_id', (int) $search);
                }
            });
        });

        if ($this->flag($filters, 'has_retries')) {
            $query->where(function (Builder $q) {
                $q->whereNotNull('retry_of_attempt_id')
                    ->orWhereHas('retryAttempts');
            });
        }

        if ($this->flag($filters, 'has_duplicates')) {
            $query->where('duplicate_request_count', '>', 0);
        }

        if ($this->flag($filters, 'has_timeout')) {
            $query->where(function (Builder $q) {
                $q->where('failure_category', EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT)
                    ->orWhere('status', PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value);
            });
        }

        if ($this->flag($filters, 'has_technical_error')) {
            $query->where(function (Builder $q) {
                $q->where('status', PaymentAuthenticationAttemptStatus::TechnicalError->value)
                    ->orWhereIn('failure_category', [
                        EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_ERROR,
                        EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_UNAVAILABLE,
                        EfevooPay3dsResultClassifier::CATEGORY_NETWORK_ERROR,
                        EfevooPay3dsResultClassifier::CATEGORY_CONFIGURATION_ERROR,
                        EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
                    ]);
            });
        }

        if ($this->flag($filters, 'active')) {
            $query->whereIn('status', PaymentAuthenticationAttemptStatus::activeValues());
        }

        if ($this->flag($filters, 'terminal')) {
            $query->whereIn('status', PaymentAuthenticationAttemptStatus::terminalValues());
        }

        if (($filters['outcome'] ?? null) === 'expired_cancelled') {
            $query->whereIn('status', [
                PaymentAuthenticationAttemptStatus::Expired->value,
                PaymentAuthenticationAttemptStatus::Cancelled->value,
            ]);
        }

        if (($filters['outcome'] ?? null) === 'unknown_pending') {
            $query->where(function (Builder $q) {
                $q->whereIn('status', [
                    PaymentAuthenticationAttemptStatus::Unknown->value,
                    PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
                ])->orWhere('failure_category', EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN);
            });
        }

        if ($this->flag($filters, 'multiple_get_link')) {
            $query->where('provider_link_call_count', '>', 1);
        }

        if ($this->flag($filters, 'multiple_token_card')) {
            $query->where('tokenization_call_count', '>', 1);
        }

        if ($this->flag($filters, 'tokenization_confirmation_pending')) {
            $query->where('status', PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value);
        }

        if ($this->flag($filters, 'possible_duplicate_operation')) {
            $query->where(function (Builder $q) {
                $q->where('provider_link_call_count', '>', 1)
                    ->orWhere('tokenization_call_count', '>', 1)
                    ->orWhereHas('events', function (Builder $events) {
                        $events->whereIn('event_type', [
                            PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value,
                            PaymentAuthenticationAttemptEventType::PossibleDuplicateVerificationOperation->value,
                        ]);
                    });
            });
        }

        if ($this->flag($filters, 'excessive_get_status')) {
            $max = (int) config('efevoopay.polling.max_external_status_polls', 60);
            $query->where('status_poll_call_count', '>', $max);
        }
    }

    private function flag(array $filters, string $key): bool
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) {
            return false;
        }

        return filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
