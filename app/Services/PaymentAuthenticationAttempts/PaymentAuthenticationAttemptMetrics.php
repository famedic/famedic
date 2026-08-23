<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Support\EfevooPay3dsResultClassifier;

class PaymentAuthenticationAttemptMetrics
{
    public function __construct(
        private PaymentAuthenticationAttemptQuery $query,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(array $filters, PaymentAuthenticationAttemptDateRange $range): array
    {
        $base = $this->query->filteredQuery($filters, $range);
        $durationExpression = $base->getConnection()->getDriverName() === 'sqlite'
            ? 'AVG(CASE WHEN finished_at IS NOT NULL THEN (julianday(finished_at) - julianday(started_at)) * 86400 END)'
            : 'AVG(CASE WHEN finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) END)';
        $aggregates = (clone $base)
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT customer_id) as customers_affected')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed', [PaymentAuthenticationAttemptStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as declined', [PaymentAuthenticationAttemptStatus::Declined->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [PaymentAuthenticationAttemptStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as expired', [PaymentAuthenticationAttemptStatus::Expired->value])
            ->selectRaw('SUM(CASE WHEN status IN ('.self::placeholders(PaymentAuthenticationAttemptStatus::terminalValues()).') THEN 1 ELSE 0 END) as terminal', PaymentAuthenticationAttemptStatus::terminalValues())
            ->selectRaw('SUM(CASE WHEN status IN ('.self::placeholders(PaymentAuthenticationAttemptStatus::activeValues()).') THEN 1 ELSE 0 END) as active', PaymentAuthenticationAttemptStatus::activeValues())
            ->selectRaw('SUM(CASE WHEN failure_category = ? OR status = ? THEN 1 ELSE 0 END) as timeouts', [
                EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? OR failure_category IN ('.self::placeholders($this->technicalCategories()).') THEN 1 ELSE 0 END) as technical_errors', [
                PaymentAuthenticationAttemptStatus::TechnicalError->value,
                ...$this->technicalCategories(),
            ])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) OR failure_category = ? THEN 1 ELSE 0 END) as unknown_pending', [
                PaymentAuthenticationAttemptStatus::Unknown->value,
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
                EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
            ])
            ->selectRaw('SUM(CASE WHEN retry_of_attempt_id IS NOT NULL THEN 1 ELSE 0 END) as manual_retries')
            ->selectRaw('SUM(CASE WHEN duplicate_request_count > 0 THEN 1 ELSE 0 END) as duplicate_attempts')
            ->selectRaw('SUM(duplicate_request_count) as duplicate_blocked_count')
            ->selectRaw($durationExpression.' as average_duration_seconds')
            ->selectRaw('AVG(status_poll_call_count) as average_polls')
            ->first();

        $total = (int) ($aggregates->total ?? 0);
        $completed = (int) ($aggregates->completed ?? 0);
        $terminal = (int) ($aggregates->terminal ?? 0);
        $successRate = $terminal > 0 ? round(($completed / $terminal) * 100, 1) : null;

        $recoveredRoots = (int) ($aggregates->manual_retries ?? 0) > 0
            ? $this->query->recoveredRootIds(
                (clone $base)->whereNull('retry_of_attempt_id')
            )
            : collect();

        return [
            'total' => $total,
            'completed' => $completed,
            'success_rate' => $successRate,
            'success_rate_denominator' => $terminal,
            'declined' => (int) ($aggregates->declined ?? 0),
            'cancelled' => (int) ($aggregates->cancelled ?? 0),
            'expired' => (int) ($aggregates->expired ?? 0),
            'expired_cancelled' => (int) ($aggregates->expired ?? 0) + (int) ($aggregates->cancelled ?? 0),
            'timeouts' => (int) ($aggregates->timeouts ?? 0),
            'technical_errors' => (int) ($aggregates->technical_errors ?? 0),
            'unknown_pending' => (int) ($aggregates->unknown_pending ?? 0),
            'active' => (int) ($aggregates->active ?? 0),
            'terminal' => $terminal,
            'manual_retries' => (int) ($aggregates->manual_retries ?? 0),
            'duplicate_attempts' => (int) ($aggregates->duplicate_attempts ?? 0),
            'duplicate_blocked_count' => (int) ($aggregates->duplicate_blocked_count ?? 0),
            'customers_affected' => (int) ($aggregates->customers_affected ?? 0),
            'average_duration_seconds' => $aggregates->average_duration_seconds !== null
                ? (int) round((float) $aggregates->average_duration_seconds)
                : null,
            'average_polls' => $aggregates->average_polls !== null
                ? round((float) $aggregates->average_polls, 1)
                : null,
            'recovered_retries' => $recoveredRoots->count(),
        ];
    }

    /**
     * @return list<string>
     */
    private function technicalCategories(): array
    {
        return [
            EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_UNAVAILABLE,
            EfevooPay3dsResultClassifier::CATEGORY_NETWORK_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_CONFIGURATION_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
