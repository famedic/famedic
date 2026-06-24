<?php

namespace App\Services\Murguia;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\MurguiaSyncLog;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MurguiaInsuredQueryBuilder
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $this->applySearch($q, $s))
            ->when($filters['account_type'] ?? null, fn (Builder $q, string $t) => $this->applyAccountType($q, $t))
            ->when($filters['local_status'] ?? null, fn (Builder $q, string $s) => $this->applyLocalStatus($q, $s))
            ->when($filters['subscription_type'] ?? null, fn (Builder $q, string $t) => $this->applySubscriptionType($q, $t))
            ->when($filters['murguia_sync'] ?? null, fn (Builder $q, string $s) => $this->applyMurguiaSync($q, $s))
            ->when($filters['has_certificate_account'] ?? null, fn (Builder $q, $v) => $this->applyHasCertificateAccount($q, $v))
            ->when($filters['has_family_dependents'] ?? null, fn (Builder $q, $v) => $this->applyHasFamilyDependents($q, $v))
            ->when($filters['created_from'] ?? null, fn (Builder $q, string $d) => $q->where('customers.created_at', '>=', $d))
            ->when($filters['created_to'] ?? null, fn (Builder $q, string $d) => $q->where('customers.created_at', '<=', $d . ' 23:59:59'))
            ->when($filters['expires_from'] ?? null, fn (Builder $q, string $d) => $q->where('medical_attention_subscription_expires_at', '>=', $d))
            ->when($filters['expires_to'] ?? null, fn (Builder $q, string $d) => $q->where('medical_attention_subscription_expires_at', '<=', $d . ' 23:59:59'))
            ->when($filters['sync_from'] ?? null, fn (Builder $q, string $d) => $this->applySyncDateFrom($q, $d))
            ->when($filters['sync_to'] ?? null, fn (Builder $q, string $d) => $this->applySyncDateTo($q, $d))
            ->when($filters['payment_from'] ?? null, fn (Builder $q, string $d) => $this->applyPaymentDateFrom($q, $d))
            ->when($filters['payment_to'] ?? null, fn (Builder $q, string $d) => $this->applyPaymentDateTo($q, $d))
            ->when($filters['no_credito_empty'] ?? null, fn (Builder $q, $v) => $this->applyNoCreditoEmpty($q, $v))
            ->when($filters['no_credito_duplicate'] ?? null, fn (Builder $q, $v) => $this->applyNoCreditoDuplicate($q, $v))
            ->when($filters['email_duplicate'] ?? null, fn (Builder $q, $v) => $this->applyEmailDuplicate($q, $v));
    }

    public function baseQuery(): Builder
    {
        return Customer::query();
    }

    private function applySearch(Builder $query, string $search): void
    {
        $s = trim($search);
        if ($s === '') {
            return;
        }

        $query->where(function (Builder $q) use ($s) {
            $q->where('medical_attention_identifier', 'like', '%' . $s . '%')
                ->orWhereHas('user', function (Builder $q) use ($s) {
                    $q->where('email', 'like', '%' . $s . '%')
                        ->orWhere('name', 'like', '%' . $s . '%')
                        ->orWhere('paternal_lastname', 'like', '%' . $s . '%')
                        ->orWhere('maternal_lastname', 'like', '%' . $s . '%')
                        ->orWhere('phone', 'like', '%' . $s . '%');
                });
        });
    }

    private function applyAccountType(Builder $query, string $type): void
    {
        $morphMap = [
            'regular' => RegularAccount::class,
            'odessa' => OdessaAfiliateAccount::class,
            'familiar' => FamilyAccount::class,
            'certificate' => CertificateAccount::class,
        ];

        if (isset($morphMap[$type])) {
            $query->where('customerable_type', $morphMap[$type]);

            return;
        }

        if ($type === 'trial') {
            $query->whereHas('medicalAttentionSubscriptions', function (Builder $q) {
                $q->where('type', MedicalSubscriptionType::TRIAL);
            });

            return;
        }

        if ($type === 'institutional') {
            $query->where(function (Builder $q) {
                $q->where('customerable_type', CertificateAccount::class)
                    ->orWhereHas('medicalAttentionSubscriptions', function (Builder $q) {
                        $q->where('type', MedicalSubscriptionType::INSTITUTIONAL);
                    });
            });
        }
    }

    private function applyLocalStatus(Builder $query, string $status): void
    {
        match ($status) {
            'active' => $query->where('medical_attention_subscription_expires_at', '>', now()),
            'inactive' => $query->where(function (Builder $q) {
                $q->whereNull('medical_attention_subscription_expires_at')
                    ->orWhere('medical_attention_subscription_expires_at', '<=', now());
            }),
            'expired' => $query->whereNotNull('medical_attention_subscription_expires_at')
                ->where('medical_attention_subscription_expires_at', '<=', now()),
            'no_subscription' => $query->whereDoesntHave('medicalAttentionSubscriptions'),
            default => null,
        };
    }

    private function applySubscriptionType(Builder $query, string $type): void
    {
        if ($type === 'none') {
            $query->whereDoesntHave('medicalAttentionSubscriptions');

            return;
        }

        $enumMap = [
            'trial' => MedicalSubscriptionType::TRIAL,
            'regular' => MedicalSubscriptionType::REGULAR,
            'institutional' => MedicalSubscriptionType::INSTITUTIONAL,
            'family_member' => MedicalSubscriptionType::FAMILY_MEMBER,
        ];

        if (! isset($enumMap[$type])) {
            return;
        }

        $query->whereHas('medicalAttentionSubscriptions', function (Builder $q) use ($enumMap, $type) {
            $q->where('type', $enumMap[$type]);
        });
    }

    private function applyMurguiaSync(Builder $query, string $status): void
    {
        match ($status) {
            'synced' => $query->whereHas('medicalAttentionSubscriptions', function (Builder $q) {
                $q->whereNotNull('synced_with_murguia_at');
            }),
            'pending' => $query
                ->whereHas('medicalAttentionSubscriptions')
                ->whereDoesntHave('medicalAttentionSubscriptions', function (Builder $q) {
                    $q->whereNotNull('synced_with_murguia_at');
                })
                ->whereDoesntHave('murguiaSyncLogs', function (Builder $q) {
                    $q->where('status', MurguiaSyncLog::STATUS_FAILED);
                }),
            'error' => $query->whereHas('murguiaSyncLogs', function (Builder $q) {
                $q->where('status', MurguiaSyncLog::STATUS_FAILED);
            }),
            'no_log' => $query->whereDoesntHave('murguiaSyncLogs'),
            default => null,
        };
    }

    private function applyHasCertificateAccount(Builder $query, mixed $value): void
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === true) {
            $query->where('customerable_type', CertificateAccount::class);
        } elseif ($bool === false) {
            $query->where('customerable_type', '!=', CertificateAccount::class);
        }
    }

    private function applyHasFamilyDependents(Builder $query, mixed $value): void
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === true) {
            $query->whereHas('familyMembers');
        } elseif ($bool === false) {
            $query->whereDoesntHave('familyMembers');
        }
    }

    private function applySyncDateFrom(Builder $query, string $date): void
    {
        $query->whereHas('medicalAttentionSubscriptions', function (Builder $q) use ($date) {
            $q->where('synced_with_murguia_at', '>=', $date);
        });
    }

    private function applySyncDateTo(Builder $query, string $date): void
    {
        $query->whereHas('medicalAttentionSubscriptions', function (Builder $q) use ($date) {
            $q->where('synced_with_murguia_at', '<=', $date . ' 23:59:59');
        });
    }

    private function applyPaymentDateFrom(Builder $query, string $date): void
    {
        $query->whereHas('medicalAttentionSubscriptions.transactions', function (Builder $q) use ($date) {
            $q->where('transactions.created_at', '>=', $date);
        });
    }

    private function applyPaymentDateTo(Builder $query, string $date): void
    {
        $query->whereHas('medicalAttentionSubscriptions.transactions', function (Builder $q) use ($date) {
            $q->where('transactions.created_at', '<=', $date . ' 23:59:59');
        });
    }

    private function applyNoCreditoEmpty(Builder $query, mixed $value): void
    {
        if (! $this->isTruthyFilter($value)) {
            return;
        }

        $query->where(function (Builder $q) {
            $q->whereNull('medical_attention_identifier')
                ->orWhere('medical_attention_identifier', '');
        });
    }

    private function applyNoCreditoDuplicate(Builder $query, mixed $value): void
    {
        if (! $this->isTruthyFilter($value)) {
            return;
        }

        $query->whereNotNull('medical_attention_identifier')
            ->where('medical_attention_identifier', '!=', '')
            ->whereIn('medical_attention_identifier', $this->duplicateMedicalAttentionIdentifiersSubquery());
    }

    private function applyEmailDuplicate(Builder $query, mixed $value): void
    {
        if (! $this->isTruthyFilter($value)) {
            return;
        }

        $query->whereHas('user', function (Builder $q) {
            $q->whereIn('email', $this->duplicateEmailsSubquery());
        });
    }

    private function isTruthyFilter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function duplicateMedicalAttentionIdentifiersSubquery()
    {
        return DB::table('customers')
            ->select('medical_attention_identifier')
            ->whereNotNull('medical_attention_identifier')
            ->where('medical_attention_identifier', '!=', '')
            ->whereNull('deleted_at')
            ->groupBy('medical_attention_identifier')
            ->havingRaw('COUNT(*) > 1');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function duplicateEmailsSubquery()
    {
        return DB::table('users')
            ->select('email')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1');
    }
}
