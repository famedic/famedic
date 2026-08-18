<?php

namespace App\Services\Odessa\Reconciliation;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use Illuminate\Support\Facades\DB;
use stdClass;

class OdessaReconciliationDbIndex
{
    /** @var array<string, list<stdClass>> */
    public array $activeByOdessaId = [];

    /** @var array<string, list<stdClass>> */
    public array $trashedByOdessaId = [];

    /** @var array<string, list<stdClass>> */
    public array $activeByCompanyPartner = [];

    /** @var array<string, list<stdClass>> */
    public array $trashedByCompanyPartner = [];

    /** @var array<string, list<stdClass>> */
    public array $activeByMembership = [];

    /** @var array<string, list<stdClass>> */
    public array $activeByEmail = [];

    /** @var array<string, list<stdClass>> */
    public array $activeByIdentity = [];

    /** @var array<string, list<stdClass>> */
    public array $activeByLooseIdentity = [];

    /** @var array<string, list<stdClass>> */
    public array $trashedByEmail = [];

    /** @var array<string, list<stdClass>> */
    public array $trashedByIdentity = [];

    /** @var array<string, list<stdClass>> */
    public array $trashedByLooseIdentity = [];

    /** @var array<int, stdClass> */
    public array $lastMurguiaLogByCustomerId = [];

    /** @var array<string, stdClass> */
    public array $lastMurguiaLogByEmail = [];

    /** @var array<string, stdClass> */
    public array $lastMurguiaLogByMembership = [];

    /** @var array<string, list<int>> */
    public array $companyInternalIdsByExternal = [];

    public static function build(): self
    {
        $index = new self;
        $index->loadCompanies();
        $index->loadCustomerRows();
        $index->loadMurguiaLogs();

        return $index;
    }

    private function loadCompanies(): void
    {
        $companies = DB::table('odessa_afiliated_companies')
            ->select('id', 'odessa_identifier', 'deleted_at')
            ->get();

        foreach ($companies as $company) {
            if ($company->deleted_at !== null) {
                continue;
            }

            $external = OdessaReconciliationNormalizer::identifier($company->odessa_identifier);
            if ($external === null) {
                continue;
            }

            $this->companyInternalIdsByExternal[$external][] = (int) $company->id;
        }
    }

    private function loadCustomerRows(): void
    {
        $rows = DB::table('users as u')
            ->leftJoin('customers as c', 'c.user_id', '=', 'u.id')
            ->leftJoin('odessa_afiliate_accounts as oaa', function ($join) {
                $join->on('c.customerable_id', '=', 'oaa.id')
                    ->where('c.customerable_type', '=', OdessaAfiliateAccount::class);
            })
            ->leftJoin('odessa_afiliated_companies as oac', 'oac.id', '=', 'oaa.odessa_afiliated_company_id')
            ->select([
                'u.id as user_id',
                'u.name',
                'u.paternal_lastname',
                'u.maternal_lastname',
                'u.email',
                'u.birth_date',
                'c.id as customer_id',
                'c.customerable_type',
                'c.customerable_id',
                'c.medical_attention_identifier',
                'c.medical_attention_subscription_expires_at',
                'c.deleted_at as customer_deleted_at',
                'oaa.id as odessa_account_id',
                'oaa.odessa_identifier',
                'oaa.partner_identifier',
                'oaa.odessa_afiliated_company_id as company_internal_id',
                'oaa.deleted_at as odessa_deleted_at',
                'oac.odessa_identifier as company_external_id_db',
                'oac.name as company_name',
                'oac.deleted_at as company_deleted_at',
            ])
            ->get();

        $subscriptions = $this->subscriptionsByCustomerId($rows->pluck('customer_id')->filter()->unique()->values()->all());

        foreach ($rows as $row) {
            $this->attachSubscription($row, $subscriptions[(int) $row->customer_id] ?? []);
            $this->decorateRow($row);
            $this->indexRow($row);
        }
    }

    /** @param list<int> $customerIds @return array<int, list<stdClass>> */
    private function subscriptionsByCustomerId(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $subscriptions = DB::table('medical_attention_subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->orderBy('customer_id')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        $grouped = [];
        foreach ($subscriptions as $subscription) {
            $grouped[(int) $subscription->customer_id][] = $subscription;
        }

        return $grouped;
    }

    /** @param list<stdClass> $subscriptions */
    private function attachSubscription(stdClass $row, array $subscriptions): void
    {
        $active = [];
        $nonDeleted = [];
        $deleted = [];

        foreach ($subscriptions as $subscription) {
            if ($subscription->deleted_at !== null) {
                $deleted[] = $subscription;

                continue;
            }

            $nonDeleted[] = $subscription;
            if ($subscription->start_date !== null
                && $subscription->end_date !== null
                && now()->betweenIncluded($subscription->start_date, $subscription->end_date)) {
                $active[] = $subscription;
            }
        }

        $selected = $active[0] ?? $this->mostRelevantSubscription($nonDeleted);
        $row->subscription_count = count($subscriptions);
        $row->non_deleted_subscription_count = count($nonDeleted);
        $row->deleted_subscription_count = count($deleted);
        $row->has_only_deleted_subscription = $subscriptions !== [] && $nonDeleted === [];

        $row->subscription_id = $selected?->id;
        $row->subscription_type = $selected?->type;
        $row->subscription_start_date = $selected?->start_date;
        $row->subscription_end_date = $selected?->end_date;
        $row->synced_with_murguia_at = $selected?->synced_with_murguia_at;
        $row->subscription_deleted_at = $selected?->deleted_at;
    }

    /** @param list<stdClass> $subscriptions */
    private function mostRelevantSubscription(array $subscriptions): ?stdClass
    {
        usort($subscriptions, fn (stdClass $left, stdClass $right) => strcmp((string) $right->end_date, (string) $left->end_date)
            ?: ((int) $right->id <=> (int) $left->id));

        return $subscriptions[0] ?? null;
    }

    private function decorateRow(stdClass $row): void
    {
        $row->email_normalized = OdessaReconciliationNormalizer::email($row->email);
        $row->identity_key = OdessaReconciliationNormalizer::identityKey(
            $row->name,
            $row->paternal_lastname,
            $row->maternal_lastname,
            $row->birth_date,
        );
        $row->loose_identity_key = OdessaReconciliationNormalizer::comparableName(
            $row->name,
            $row->paternal_lastname,
            $row->maternal_lastname,
        );
        $row->is_trashed = $row->customer_deleted_at !== null
            || $row->odessa_deleted_at !== null
            || $row->company_deleted_at !== null;
        $row->subscription_active = $row->subscription_start_date !== null
            && $row->subscription_end_date !== null
            && now()->betweenIncluded($row->subscription_start_date, $row->subscription_end_date);
        $row->subscription_status = match (true) {
            $row->subscription_active => 'ACTIVE',
            $row->has_only_deleted_subscription => 'DELETED_ONLY',
            ! $row->subscription_id => 'MISSING',
            $row->subscription_start_date !== null && now()->lt($row->subscription_start_date) => 'FUTURE',
            default => 'EXPIRED',
        };
        $row->account_type_label = $this->accountTypeLabel($row->customerable_type);
        $row->subscription_type_label = $this->subscriptionTypeLabel($row->subscription_type);
        $row->subscription_status_label = $this->subscriptionStatusLabel($row->subscription_status);
    }

    private function accountTypeLabel(?string $customerableType): ?string
    {
        return match ($customerableType) {
            OdessaAfiliateAccount::class => 'Odessa',
            RegularAccount::class => 'Regular',
            FamilyAccount::class => 'Familiar',
            CertificateAccount::class => 'Certificado / convenio',
            null => null,
            default => class_basename($customerableType),
        };
    }

    private function subscriptionTypeLabel(?string $type): ?string
    {
        if (! $type) {
            return 'Ninguna';
        }

        if ($type === MedicalSubscriptionType::INSTITUTIONAL->value) {
            return 'Institucional Odessa';
        }

        $enum = MedicalSubscriptionType::tryFrom($type);

        return $enum?->label() ?? $type;
    }

    private function subscriptionStatusLabel(?string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'Activa',
            'FUTURE' => 'Futura',
            'EXPIRED' => 'Vencida',
            'MISSING' => 'Sin membresía',
            'DELETED_ONLY' => 'Eliminada/Histórica',
            default => 'Sin registro',
        };
    }

    private function indexRow(stdClass $row): void
    {
        $isActive = ! $row->is_trashed;

        if ($row->odessa_identifier) {
            if ($isActive) {
                $this->push($this->activeByOdessaId, (string) $row->odessa_identifier, $row);
            } else {
                $this->push($this->trashedByOdessaId, (string) $row->odessa_identifier, $row);
            }
        }

        if ($row->company_external_id_db && $row->partner_identifier) {
            $key = $row->company_external_id_db.'|'.$row->partner_identifier;
            if ($isActive) {
                $this->push($this->activeByCompanyPartner, $key, $row);
            } else {
                $this->push($this->trashedByCompanyPartner, $key, $row);
            }
        }

        if ($isActive && $row->medical_attention_identifier) {
            $this->push($this->activeByMembership, (string) $row->medical_attention_identifier, $row);
        }

        if ($isActive && $row->email_normalized) {
            $this->push($this->activeByEmail, $row->email_normalized, $row);
        } elseif (! $isActive && $row->email_normalized) {
            $this->push($this->trashedByEmail, $row->email_normalized, $row);
        }

        if ($isActive && $row->identity_key !== '|||') {
            $this->push($this->activeByIdentity, $row->identity_key, $row);
        } elseif (! $isActive && $row->identity_key !== '|||') {
            $this->push($this->trashedByIdentity, $row->identity_key, $row);
        }

        if ($isActive && $row->loose_identity_key !== '') {
            $this->push($this->activeByLooseIdentity, $row->loose_identity_key, $row);
        } elseif (! $isActive && $row->loose_identity_key !== '') {
            $this->push($this->trashedByLooseIdentity, $row->loose_identity_key, $row);
        }
    }

    private function loadMurguiaLogs(): void
    {
        $logs = DB::table('murguia_sync_logs')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($logs as $log) {
            if ($log->customer_id && ! isset($this->lastMurguiaLogByCustomerId[(int) $log->customer_id])) {
                $this->lastMurguiaLogByCustomerId[(int) $log->customer_id] = $log;
            }

            $email = OdessaReconciliationNormalizer::email($log->email);
            if ($email && ! isset($this->lastMurguiaLogByEmail[$email])) {
                $this->lastMurguiaLogByEmail[$email] = $log;
            }

            $membership = OdessaReconciliationNormalizer::identifier($log->medical_attention_identifier);
            if ($membership && ! isset($this->lastMurguiaLogByMembership[$membership])) {
                $this->lastMurguiaLogByMembership[$membership] = $log;
            }
        }
    }

    /** @param array<string, list<stdClass>> $index */
    private function push(array &$index, string $key, stdClass $row): void
    {
        $index[$key] ??= [];
        $index[$key][] = $row;
    }
}
