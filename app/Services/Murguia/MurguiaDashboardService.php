<?php

namespace App\Services\Murguia;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\MurguiaSyncLog;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MurguiaDashboardService
{
    public function __construct(
        private MurguiaInsuredQueryBuilder $queryBuilder
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDashboardData(array $filters): array
    {
        $baseQuery = $this->filteredQuery($filters);

        return [
            'summary' => $this->buildSummary($baseQuery),
            'charts' => $this->buildCharts($baseQuery),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = $this->queryBuilder->baseQuery();

        return $this->queryBuilder->apply($query, $filters);
    }

    /**
     * @return array<string, int>
     */
    private function buildSummary(Builder $baseQuery): array
    {
        $clone = fn () => (clone $baseQuery);

        return [
            'total_customers' => $clone()->count(),
            'with_certificate_account' => $clone()->where('customerable_type', CertificateAccount::class)->count(),
            'local_membership_active' => $clone()->where('medical_attention_subscription_expires_at', '>', now())->count(),
            'subscription_vigente' => $clone()->whereHas('medicalAttentionSubscriptions', fn (Builder $q) => $q->active())->count(),
            'expired' => $clone()
                ->whereNotNull('medical_attention_subscription_expires_at')
                ->where('medical_attention_subscription_expires_at', '<=', now())
                ->count(),
            'odessa' => $clone()->where('customerable_type', OdessaAfiliateAccount::class)->count(),
            'regular' => $clone()->where('customerable_type', RegularAccount::class)->count(),
            'familiar' => $clone()->where('customerable_type', FamilyAccount::class)->count(),
            'trial' => $clone()->whereHas('medicalAttentionSubscriptions', fn (Builder $q) => $q->where('type', MedicalSubscriptionType::TRIAL))->count(),
            'institutional' => $clone()->where(function (Builder $q) {
                $q->where('customerable_type', CertificateAccount::class)
                    ->orWhereHas('medicalAttentionSubscriptions', fn (Builder $q) => $q->where('type', MedicalSubscriptionType::INSTITUTIONAL));
            })->count(),
            'family_dependents' => $clone()->where('customerable_type', FamilyAccount::class)->count(),
            'murguia_synced' => $clone()->whereHas('medicalAttentionSubscriptions', fn (Builder $q) => $q->whereNotNull('synced_with_murguia_at'))->count(),
            'murguia_sync_error' => $clone()->whereHas('murguiaSyncLogs', fn (Builder $q) => $q->where('status', MurguiaSyncLog::STATUS_FAILED))->count(),
            'no_lab_purchases' => $clone()->whereDoesntHave('laboratoryPurchases')->count(),
            'no_subscription' => $clone()->whereDoesntHave('medicalAttentionSubscriptions')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCharts(Builder $baseQuery): array
    {
        $customerIds = (clone $baseQuery)->select('customers.id');

        return [
            'membership_distribution' => $this->membershipDistribution($customerIds),
            'local_status_distribution' => $this->localStatusDistribution($baseQuery),
            'account_type_bars' => $this->accountTypeBars($baseQuery),
            'sync_status_bars' => $this->syncStatusBars($baseQuery),
            'monthly_signups' => $this->monthlySignups($baseQuery),
            'monthly_payments' => $this->monthlyPayments($customerIds),
        ];
    }

    /**
     * @param  Builder<Customer>  $customerIds
     * @return list<array{key: string, label: string, value: int, color: string}>
     */
    private function membershipDistribution(Builder $customerIds): array
    {
        $colors = [
            'trial' => '#f59e0b',
            'regular' => '#009ad8',
            'institutional' => '#6366f1',
            'family_member' => '#10b981',
            'none' => '#94a3b8',
        ];

        $labels = [
            'trial' => 'Trial / Prueba',
            'regular' => 'Regular',
            'institutional' => 'Institucional',
            'family_member' => 'Miembro familiar',
            'none' => 'Sin suscripción',
        ];

        $rows = DB::table('customers as c')
            ->leftJoin(DB::raw('(
                SELECT mas.customer_id, mas.type
                FROM medical_attention_subscriptions mas
                INNER JOIN (
                    SELECT customer_id, MAX(end_date) as max_end
                    FROM medical_attention_subscriptions
                    WHERE deleted_at IS NULL
                    GROUP BY customer_id
                ) latest ON latest.customer_id = mas.customer_id AND latest.max_end = mas.end_date
                WHERE mas.deleted_at IS NULL
            ) as sub'), 'sub.customer_id', '=', 'c.id')
            ->whereIn('c.id', $customerIds)
            ->whereNull('c.deleted_at')
            ->selectRaw('COALESCE(sub.type, "none") as sub_type, COUNT(*) as cnt')
            ->groupBy('sub_type')
            ->get();

        $segments = [];
        foreach ($rows as $row) {
            $key = $row->sub_type;
            $segments[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'value' => (int) $row->cnt,
                'color' => $colors[$key] ?? '#64748b',
            ];
        }

        return $segments;
    }

    /**
     * @return list<array{key: string, label: string, value: int, color: string}>
     */
    private function localStatusDistribution(Builder $baseQuery): array
    {
        $active = (clone $baseQuery)->where('medical_attention_subscription_expires_at', '>', now())->count();
        $expired = (clone $baseQuery)
            ->whereNotNull('medical_attention_subscription_expires_at')
            ->where('medical_attention_subscription_expires_at', '<=', now())
            ->count();
        $noSub = (clone $baseQuery)->whereDoesntHave('medicalAttentionSubscriptions')->count();

        return [
            ['key' => 'active', 'label' => 'Activos', 'value' => $active, 'color' => '#10b981'],
            ['key' => 'expired', 'label' => 'Vencidos', 'value' => $expired, 'color' => '#ef4444'],
            ['key' => 'no_subscription', 'label' => 'Sin suscripción', 'value' => $noSub, 'color' => '#94a3b8'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: int, color: string}>
     */
    private function accountTypeBars(Builder $baseQuery): array
    {
        $counts = [
            'regular' => (clone $baseQuery)->where('customerable_type', RegularAccount::class)->count(),
            'odessa' => (clone $baseQuery)->where('customerable_type', OdessaAfiliateAccount::class)->count(),
            'familiar' => (clone $baseQuery)->where('customerable_type', FamilyAccount::class)->count(),
            'certificate' => (clone $baseQuery)->where('customerable_type', CertificateAccount::class)->count(),
        ];

        $labels = [
            'regular' => 'Regular',
            'odessa' => 'Odessa',
            'familiar' => 'Familiar',
            'certificate' => 'Certificado',
        ];

        $colors = [
            'regular' => '#009ad8',
            'odessa' => '#6366f1',
            'familiar' => '#10b981',
            'certificate' => '#a855f7',
        ];

        return collect($counts)->map(fn (int $value, string $key) => [
            'key' => $key,
            'label' => $labels[$key],
            'value' => $value,
            'color' => $colors[$key],
        ])->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, value: int, color: string}>
     */
    private function syncStatusBars(Builder $baseQuery): array
    {
        $synced = (clone $baseQuery)->whereHas('medicalAttentionSubscriptions', fn (Builder $q) => $q->whereNotNull('synced_with_murguia_at'))->count();
        $error = (clone $baseQuery)->whereHas('murguiaSyncLogs', fn (Builder $q) => $q->where('status', MurguiaSyncLog::STATUS_FAILED))->count();
        $noLog = (clone $baseQuery)->whereDoesntHave('murguiaSyncLogs')->count();
        $pending = (clone $baseQuery)
            ->whereHas('medicalAttentionSubscriptions')
            ->whereDoesntHave('medicalAttentionSubscriptions', fn (Builder $q) => $q->whereNotNull('synced_with_murguia_at'))
            ->whereDoesntHave('murguiaSyncLogs', fn (Builder $q) => $q->where('status', MurguiaSyncLog::STATUS_FAILED))
            ->count();

        return [
            ['key' => 'synced', 'label' => 'Sincronizado', 'value' => $synced, 'color' => '#10b981'],
            ['key' => 'pending', 'label' => 'Pendiente', 'value' => $pending, 'color' => '#f59e0b'],
            ['key' => 'error', 'label' => 'Error', 'value' => $error, 'color' => '#ef4444'],
            ['key' => 'no_log', 'label' => 'Sin log', 'value' => $noLog, 'color' => '#94a3b8'],
        ];
    }

    /**
     * @return list<array{month: string, label: string, value: int}>
     */
    private function monthlySignups(Builder $baseQuery): array
    {
        $tz = 'America/Monterrey';
        $start = Carbon::now($tz)->subMonths(11)->startOfMonth();

        $rows = (clone $baseQuery)
            ->where('customers.created_at', '>=', $start->copy()->utc())
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(customers.created_at, '+00:00', '-06:00'), '%Y-%m') as month_key, COUNT(*) as cnt")
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->pluck('cnt', 'month_key');

        return $this->fillMonthlySeries($rows, $start, $tz);
    }

    /**
     * @param  Builder<Customer>  $customerIds
     * @return list<array{month: string, label: string, value: int}>
     */
    private function monthlyPayments(Builder $customerIds): array
    {
        $tz = 'America/Monterrey';
        $start = Carbon::now($tz)->subMonths(11)->startOfMonth();

        $rows = DB::table('transactions')
            ->join('transactionables', 'transactions.id', '=', 'transactionables.transaction_id')
            ->join('medical_attention_subscriptions', function ($join) {
                $join->on('transactionables.transactionable_id', '=', 'medical_attention_subscriptions.id')
                    ->where('transactionables.transactionable_type', '=', 'App\\Models\\MedicalAttentionSubscription');
            })
            ->whereIn('medical_attention_subscriptions.customer_id', $customerIds)
            ->where('transactions.created_at', '>=', $start->copy()->utc())
            ->whereNull('transactions.deleted_at')
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(transactions.created_at, '+00:00', '-06:00'), '%Y-%m') as month_key, COUNT(*) as cnt")
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->pluck('cnt', 'month_key');

        return $this->fillMonthlySeries($rows, $start, $tz);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $rows
     * @return list<array{month: string, label: string, value: int}>
     */
    private function fillMonthlySeries($rows, Carbon $start, string $tz): array
    {
        $out = [];
        $cursor = $start->copy();

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $out[] = [
                'month' => $key,
                'label' => $cursor->isoFormat('MMM YYYY'),
                'value' => (int) ($rows[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $out;
    }
}
