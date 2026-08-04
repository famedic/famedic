<?php

namespace App\Services\Murguia;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\MurguiaSyncLog;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\LazyCollection;

class MurguiaReportService
{
    public const FILTER_KEYS = [
        'search',
        'account_type',
        'local_status',
        'subscription_type',
        'murguia_sync',
        'has_certificate_account',
        'has_family_dependents',
        'created_from',
        'created_to',
        'expires_from',
        'expires_to',
        'sync_from',
        'sync_to',
        'payment_from',
        'payment_to',
        'no_credito_empty',
        'no_credito_duplicate',
        'email_duplicate',
        'preset',
    ];

    public const PRESETS = [
        'all' => [],
        'active' => ['local_status' => 'active'],
        'expired' => ['local_status' => 'expired'],
        'odessa' => ['account_type' => 'odessa'],
        'regular' => ['account_type' => 'regular'],
        'familiar' => ['account_type' => 'familiar'],
        'trial' => ['subscription_type' => 'trial'],
        'institutional' => ['account_type' => 'institutional'],
        'certificate' => ['account_type' => 'certificate'],
        'family_dependents' => ['has_family_dependents' => 'true'],
        'sync_error' => ['murguia_sync' => 'error'],
        'no_credito' => ['no_credito_empty' => 'true'],
        'duplicate_credito' => ['no_credito_duplicate' => 'true'],
        'duplicate_email' => ['email_duplicate' => 'true'],
    ];

    public function __construct(
        private MurguiaInsuredQueryBuilder $queryBuilder
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveFilters(array $input): array
    {
        $filters = collect($input)
            ->only(self::FILTER_KEYS)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $preset = $filters['preset'] ?? null;
        unset($filters['preset']);

        if ($preset && isset(self::PRESETS[$preset])) {
            $filters = array_merge(self::PRESETS[$preset], $filters);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildQuery(array $filters): Builder
    {
        $query = $this->queryBuilder
            ->apply($this->queryBuilder->baseQuery(), $filters)
            ->with($this->eagerLoads())
            ->orderByDesc('customers.id');

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->buildQuery($filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->mapCustomerRow($customer));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function lazyForExport(array $filters): LazyCollection
    {
        return $this->buildQuery($filters)->lazy(100);
    }

    /**
     * @return list<string>
     */
    public function columnHeadings(): array
    {
        return [
            'customer_id',
            'certificate_account_id',
            'nombre_completo',
            'email',
            'telefono',
            'no_credito',
            'tipo_cuenta',
            'tipo_suscripcion',
            'estado_local',
            'fecha_inicio_suscripcion',
            'fecha_expiracion_suscripcion',
            'dias_restantes_o_vencido',
            'origen',
            'customer_titular',
            'relacion_familiar',
            'ultima_sincronizacion_murguia',
            'estado_sync_murguia',
            'ultimo_error_sync_murguia',
            'ultima_transaccion',
            'monto_pagado',
            'metodo_proveedor_pago',
            'observaciones_conciliacion',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapCustomerRow(Customer $customer): array
    {
        $subscription = $customer->medicalAttentionSubscriptions->first();
        $localStatus = $this->resolveLocalStatus($customer);
        $syncStatus = $this->resolveSyncStatus($customer);
        $latestTransaction = $this->resolveLatestTransaction($customer);
        $lastFailedLog = $customer->murguiaSyncLogs
            ->first(fn (MurguiaSyncLog $log) => $log->status === MurguiaSyncLog::STATUS_FAILED);
        $lastSyncedAt = $customer->medicalAttentionSubscriptions
            ->filter(fn ($s) => $s->synced_with_murguia_at !== null)
            ->sortByDesc(fn ($s) => $s->synced_with_murguia_at)
            ->first()
            ?->synced_with_murguia_at;

        $accountType = $this->resolveAccountType($customer);
        $subscriptionType = $this->resolveSubscriptionTypeLabel($subscription);
        $origin = $this->resolveOrigin($customer, $subscription);
        $familyMeta = $this->resolveFamilyMeta($customer);

        $expiresAt = $subscription?->end_date
            ? Carbon::parse($subscription->end_date)->endOfDay()
            : $customer->medical_attention_subscription_expires_at;

        return [
            'customer_id' => $customer->id,
            'certificate_account_id' => $customer->customerable_type === CertificateAccount::class
                ? $customer->customerable?->id
                : null,
            'full_name' => $this->resolveFullName($customer),
            'email' => $customer->user?->email,
            'phone' => $customer->user?->full_phone,
            'medical_attention_identifier' => $customer->medical_attention_identifier,
            'account_type' => $accountType,
            'subscription_type' => $subscriptionType,
            'local_status' => $localStatus,
            'subscription_start_date' => $subscription?->start_date?->toDateString(),
            'subscription_end_date' => $subscription?->end_date?->toDateString(),
            'days_remaining_or_overdue' => $this->formatDaysRemaining($expiresAt),
            'origin' => $origin,
            'parent_customer' => $familyMeta['parent_name'],
            'family_relationship' => $familyMeta['kinship'],
            'last_murguia_sync_at' => $lastSyncedAt?->toIso8601String(),
            'murguia_sync_status' => $syncStatus,
            'last_sync_error' => $lastFailedLog?->message,
            'last_transaction_at' => $latestTransaction?->created_at?->toIso8601String(),
            'last_transaction_amount' => $latestTransaction?->transaction_amount_cents !== null
                ? formattedCentsPrice($latestTransaction->transaction_amount_cents)
                : null,
            'payment_method' => $latestTransaction?->payment_method ?? $latestTransaction?->gateway,
            'reconciliation_notes' => $this->buildReconciliationNotes(
                $customer,
                $localStatus,
                $syncStatus,
                $subscriptionType
            ),
        ];
    }

    /**
     * @return array<int, string|int|null>
     */
    public function mapCustomerToExportRow(Customer $customer): array
    {
        $row = $this->mapCustomerRow($customer);

        return [
            $row['customer_id'],
            $row['certificate_account_id'],
            $row['full_name'],
            $row['email'],
            $row['phone'],
            $row['medical_attention_identifier'],
            $row['account_type'],
            $row['subscription_type'],
            $row['local_status'],
            $row['subscription_start_date'],
            $row['subscription_end_date'],
            $row['days_remaining_or_overdue'],
            $row['origin'],
            $row['parent_customer'],
            $row['family_relationship'],
            $row['last_murguia_sync_at'],
            $row['murguia_sync_status'],
            $row['last_sync_error'],
            $row['last_transaction_at'],
            $row['last_transaction_amount'],
            $row['payment_method'],
            $row['reconciliation_notes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eagerLoads(): array
    {
        return [
            'user',
            'customerable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    FamilyAccount::class => ['parentCustomer.user'],
                ]);
            },
            'medicalAttentionSubscriptions' => fn ($q) => $q->orderByDesc('end_date'),
            'medicalAttentionSubscriptions.transactions' => fn ($q) => $q->orderByDesc('transactions.created_at'),
            'murguiaSyncLogs' => fn ($q) => $q->orderByDesc('id')->limit(20),
        ];
    }

    private function resolveFullName(Customer $customer): ?string
    {
        if ($customer->customerable_type === FamilyAccount::class) {
            return $customer->customerable?->full_name;
        }

        return $customer->user?->full_name;
    }

    private function resolveAccountType(Customer $customer): string
    {
        return match ($customer->customerable_type) {
            OdessaAfiliateAccount::class => 'Odessa',
            FamilyAccount::class => 'Familiar',
            CertificateAccount::class => 'CertificateAccount',
            RegularAccount::class => 'Regular',
            default => $customer->customerable_type ? class_basename($customer->customerable_type) : 'sin clasificar',
        };
    }

    private function resolveSubscriptionTypeLabel($subscription): string
    {
        if (! $subscription) {
            return 'none';
        }

        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL => 'trial',
            MedicalSubscriptionType::REGULAR => 'regular',
            MedicalSubscriptionType::INSTITUTIONAL => 'institutional',
            MedicalSubscriptionType::FAMILY_MEMBER => 'family_member',
            default => (string) $subscription->type->value,
        };
    }

    private function resolveLocalStatus(Customer $customer): string
    {
        if ($customer->medicalAttentionSubscriptions->isEmpty()) {
            return 'no_subscription';
        }

        if ($customer->medical_attention_subscription_expires_at
            && $customer->medical_attention_subscription_expires_at->gt(now())) {
            return 'active';
        }

        if ($customer->medical_attention_subscription_expires_at
            && $customer->medical_attention_subscription_expires_at->lte(now())) {
            return 'expired';
        }

        return 'inactive';
    }

    private function resolveSyncStatus(Customer $customer): string
    {
        if ($customer->murguiaSyncLogs->contains(fn (MurguiaSyncLog $log) => $log->status === MurguiaSyncLog::STATUS_FAILED)) {
            return 'error';
        }

        if ($customer->medicalAttentionSubscriptions->contains(fn ($s) => $s->synced_with_murguia_at !== null)) {
            return 'synced';
        }

        if ($customer->medicalAttentionSubscriptions->isNotEmpty()) {
            return 'pending';
        }

        if ($customer->murguiaSyncLogs->isEmpty()) {
            return 'no_log';
        }

        return 'no_log';
    }

    private function resolveLatestTransaction(Customer $customer): ?Transaction
    {
        $latest = null;

        foreach ($customer->medicalAttentionSubscriptions as $subscription) {
            foreach ($subscription->transactions as $transaction) {
                if (! $latest || $transaction->created_at?->gt($latest->created_at)) {
                    $latest = $transaction;
                }
            }
        }

        return $latest;
    }

    /**
     * @return array{parent_name: ?string, kinship: ?string}
     */
    private function resolveFamilyMeta(Customer $customer): array
    {
        if ($customer->customerable_type !== FamilyAccount::class) {
            return ['parent_name' => null, 'kinship' => null];
        }

        /** @var FamilyAccount|null $family */
        $family = $customer->customerable;

        return [
            'parent_name' => $family?->parentCustomer?->user?->full_name,
            'kinship' => $family?->formatted_kinship ?? $family?->kinship?->label(),
        ];
    }

    private function resolveOrigin(Customer $customer, $subscription): string
    {
        $parts = [$this->resolveAccountType($customer)];

        if ($subscription?->type === MedicalSubscriptionType::TRIAL) {
            $parts[] = 'Trial';
        }

        if ($subscription?->type === MedicalSubscriptionType::INSTITUTIONAL) {
            $parts[] = 'Institucional';
        }

        return implode(', ', array_unique($parts));
    }

    private function formatDaysRemaining(?Carbon $expiresAt): ?string
    {
        if (! $expiresAt) {
            return null;
        }

        if ($expiresAt->gt(now())) {
            return $expiresAt->diffInDays(now()) . ' días restantes';
        }

        return $expiresAt->diffInDays(now()) . ' días vencido';
    }

    private function buildReconciliationNotes(
        Customer $customer,
        string $localStatus,
        string $syncStatus,
        string $subscriptionType
    ): string {
        $notes = [];

        if (blank($customer->medical_attention_identifier)) {
            $notes[] = 'Sin noCredito';
        }

        if ($syncStatus === 'error') {
            $notes[] = 'Historial de error en sync Murguía';
        }

        if ($syncStatus === 'pending' && in_array($subscriptionType, ['regular', 'institutional'], true)) {
            $notes[] = 'Suscripción sin sync registrada';
        }

        if ($localStatus === 'expired' && $syncStatus === 'synced') {
            $notes[] = 'Vencido local con sync previa';
        }

        if ($localStatus === 'active' && $syncStatus === 'no_log') {
            $notes[] = 'Activo local sin logs Murguía';
        }

        return implode('; ', $notes);
    }
}
