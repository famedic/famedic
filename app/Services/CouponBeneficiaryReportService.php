<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Models\CouponBeneficiary;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CouponBeneficiaryReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     summary: array<string, int>
     * }
     */
    public function paginate(array $filters): array
    {
        $query = $this->buildReportQuery($filters);
        $summary = $this->buildSummaryMetrics(clone $query);

        $paginator = $query
            ->orderByDesc(DB::raw('COALESCE(asg.last_assigned_at, pend.pending_last_assigned_at)'))
            ->orderBy('bk.email_key')
            ->paginate(25)
            ->withQueryString();

        $rows = $this->enrichRows(collect($paginator->items()));
        $paginator->setCollection($rows);

        return [
            'rows' => $paginator,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function exportRows(array $filters): Collection
    {
        $query = $this->buildReportQuery($filters);

        $items = $query
            ->orderByDesc(DB::raw('COALESCE(asg.last_assigned_at, pend.pending_last_assigned_at)'))
            ->orderBy('bk.email_key')
            ->get();

        return $this->enrichRows($items);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildReportQuery(array $filters): Builder
    {
        $keys = $this->beneficiaryKeysSubquery();
        $assigned = $this->assignedMetricsSubquery();
        $available = $this->availableBalanceSubquery();
        $used = $this->usedBalanceSubquery();
        $reversed = $this->reversedBalanceSubquery();
        $pending = $this->pendingMetricsSubquery();

        $query = DB::query()
            ->fromSub($keys, 'bk')
            ->leftJoin('users', 'users.id', '=', 'bk.user_id')
            ->leftJoinSub($assigned, 'asg', 'asg.user_id', '=', 'bk.user_id')
            ->leftJoinSub($available, 'av', 'av.user_id', '=', 'bk.user_id')
            ->leftJoinSub($used, 'usd', 'usd.user_id', '=', 'bk.user_id')
            ->leftJoinSub($reversed, 'rev', 'rev.user_id', '=', 'bk.user_id')
            ->leftJoinSub($pending, 'pend', 'pend.email_key', '=', 'bk.email_key')
            ->select([
                'bk.email_key',
                'bk.user_id',
                'users.email',
                'users.name as user_name',
                'users.paternal_lastname as user_paternal_lastname',
                'users.maternal_lastname as user_maternal_lastname',
                DB::raw('COALESCE(asg.assigned_coupons_count, 0) as assigned_coupons_count'),
                DB::raw('COALESCE(av.available_balance_cents, 0) as available_balance_cents'),
                DB::raw('COALESCE(usd.used_balance_cents, 0) as used_balance_cents'),
                DB::raw('COALESCE(rev.reversed_balance_cents, 0) as reversed_balance_cents'),
                DB::raw('COALESCE(pend.pending_beneficiaries_count, 0) as pending_beneficiaries_count'),
                DB::raw('asg.last_assigned_at as last_assigned_at'),
                DB::raw('pend.pending_last_assigned_at as pending_last_assigned_at'),
                DB::raw('usd.last_used_at as last_used_at'),
                DB::raw('pend.last_invitation_sent_at as last_invitation_sent_at'),
                DB::raw('COALESCE(pend.invitation_count, 0) as invitation_count'),
                DB::raw('pend.first_name as pending_first_name'),
                DB::raw('pend.paternal_lastname as pending_paternal_lastname'),
                DB::raw('pend.maternal_lastname as pending_maternal_lastname'),
                DB::raw('CASE WHEN bk.user_id IS NOT NULL THEN \'registered\' ELSE \'pending\' END as status'),
            ]);

        $this->applyFilters($query, $filters);

        return $query;
    }

    private function beneficiaryKeysSubquery(): Builder
    {
        $registered = DB::table('users')
            ->join('coupon_user', 'coupon_user.user_id', '=', 'users.id')
            ->join('coupons', 'coupons.id', '=', 'coupon_user.coupon_id')
            ->where('coupons.type', CouponType::Balance->value)
            ->selectRaw('LOWER(TRIM(users.email)) as email_key, users.id as user_id');

        $pending = DB::table('coupon_beneficiaries')
            ->where('status', CouponBeneficiaryStatus::PendingUser->value)
            ->whereNull('child_coupon_id')
            ->whereNull('user_id')
            ->whereNull('cancelled_at')
            ->selectRaw('email_normalized as email_key, NULL as user_id');

        return DB::query()
            ->fromSub($registered->unionAll($pending), 'combined')
            ->selectRaw('email_key, MAX(user_id) as user_id')
            ->groupBy('email_key');
    }

    private function assignedMetricsSubquery(): Builder
    {
        return DB::table('coupon_user')
            ->join('coupons', 'coupons.id', '=', 'coupon_user.coupon_id')
            ->where('coupons.type', CouponType::Balance->value)
            ->selectRaw('coupon_user.user_id, COUNT(*) as assigned_coupons_count, MAX(coupon_user.assigned_at) as last_assigned_at')
            ->groupBy('coupon_user.user_id');
    }

    private function availableBalanceSubquery(): Builder
    {
        $now = now();

        return DB::table('coupon_user')
            ->join('coupons', 'coupons.id', '=', 'coupon_user.coupon_id')
            ->where('coupons.type', CouponType::Balance->value)
            ->where('coupons.is_active', true)
            ->where('coupons.approval_status', CouponApprovalStatus::Active->value)
            ->where('coupons.remaining_cents', '>', 0)
            ->whereNull('coupon_user.used_at')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('coupons.valid_from')
                    ->orWhere('coupons.valid_from', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('coupons.expires_at')
                    ->orWhere('coupons.expires_at', '>=', $now);
            })
            ->selectRaw('coupon_user.user_id, SUM(coupons.remaining_cents) as available_balance_cents')
            ->groupBy('coupon_user.user_id');
    }

    private function usedBalanceSubquery(): Builder
    {
        return DB::table('coupon_transactions')
            ->whereNull('reversed_at')
            ->selectRaw('user_id, SUM(amount_used_cents) as used_balance_cents, MAX(created_at) as last_used_at')
            ->groupBy('user_id');
    }

    private function reversedBalanceSubquery(): Builder
    {
        return DB::table('coupon_transactions')
            ->whereNotNull('reversed_at')
            ->selectRaw('user_id, SUM(amount_used_cents) as reversed_balance_cents')
            ->groupBy('user_id');
    }

    private function pendingMetricsSubquery(): Builder
    {
        return DB::table('coupon_beneficiaries')
            ->where('status', CouponBeneficiaryStatus::PendingUser->value)
            ->whereNull('child_coupon_id')
            ->whereNull('user_id')
            ->whereNull('cancelled_at')
            ->selectRaw('
                email_normalized as email_key,
                COUNT(*) as pending_beneficiaries_count,
                MAX(assigned_at) as pending_last_assigned_at,
                MAX(last_invitation_sent_at) as last_invitation_sent_at,
                SUM(invitation_count) as invitation_count,
                MAX(first_name) as first_name,
                MAX(paternal_lastname) as paternal_lastname,
                MAX(maternal_lastname) as maternal_lastname
            ')
            ->groupBy('email_normalized');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('LOWER(bk.email_key) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.paternal_lastname) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.maternal_lastname) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(pend.first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(pend.paternal_lastname) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(pend.maternal_lastname) LIKE ?', [$like]);
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'registered') {
            $query->whereNotNull('bk.user_id');
        } elseif ($status === 'pending') {
            $query->whereNull('bk.user_id');
        }

        $balance = (string) ($filters['balance'] ?? 'all');
        if ($balance === 'has_available') {
            $query->whereRaw('COALESCE(av.available_balance_cents, 0) > 0');
        } elseif ($balance === 'no_available') {
            $query->whereNotNull('bk.user_id')
                ->whereRaw('COALESCE(av.available_balance_cents, 0) = 0');
        } elseif ($balance === 'has_used') {
            $query->whereRaw('COALESCE(usd.used_balance_cents, 0) > 0');
        }

        if (! empty($filters['has_pending'])) {
            $query->whereRaw('COALESCE(pend.pending_beneficiaries_count, 0) > 0');
        }

        if (! empty($filters['assigned_from'])) {
            $from = Carbon::parse($filters['assigned_from'])->startOfDay();
            $query->where(function (Builder $q) use ($from) {
                $q->where('asg.last_assigned_at', '>=', $from)
                    ->orWhere('pend.pending_last_assigned_at', '>=', $from);
            });
        }

        if (! empty($filters['assigned_to'])) {
            $to = Carbon::parse($filters['assigned_to'])->endOfDay();
            $query->where(function (Builder $q) use ($to) {
                $q->where('asg.last_assigned_at', '<=', $to)
                    ->orWhere('pend.pending_last_assigned_at', '<=', $to);
            });
        }

        if (! empty($filters['used_from'])) {
            $from = Carbon::parse($filters['used_from'])->startOfDay();
            $query->where('usd.last_used_at', '>=', $from);
        }

        if (! empty($filters['used_to'])) {
            $to = Carbon::parse($filters['used_to'])->endOfDay();
            $query->where('usd.last_used_at', '<=', $to);
        }
    }

    /**
     * @return array{
     *     total_beneficiaries: int,
     *     registered_count: int,
     *     pending_count: int,
     *     total_available_balance_cents: int,
     *     total_used_balance_cents: int,
     *     total_reversed_balance_cents: int
     * }
     */
    private function buildSummaryMetrics(Builder $query): array
    {
        $summary = (clone $query)
            ->selectRaw('
                COUNT(*) as total_beneficiaries,
                SUM(CASE WHEN bk.user_id IS NOT NULL THEN 1 ELSE 0 END) as registered_count,
                SUM(CASE WHEN bk.user_id IS NULL THEN 1 ELSE 0 END) as pending_count,
                SUM(COALESCE(av.available_balance_cents, 0)) as total_available_balance_cents,
                SUM(COALESCE(usd.used_balance_cents, 0)) as total_used_balance_cents,
                SUM(COALESCE(rev.reversed_balance_cents, 0)) as total_reversed_balance_cents
            ')
            ->first();

        return [
            'total_beneficiaries' => (int) ($summary->total_beneficiaries ?? 0),
            'registered_count' => (int) ($summary->registered_count ?? 0),
            'pending_count' => (int) ($summary->pending_count ?? 0),
            'total_available_balance_cents' => (int) ($summary->total_available_balance_cents ?? 0),
            'total_used_balance_cents' => (int) ($summary->total_used_balance_cents ?? 0),
            'total_reversed_balance_cents' => (int) ($summary->total_reversed_balance_cents ?? 0),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichRows(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $userIds = $rows->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $customerIdsByUserId = $userIds->isEmpty()
            ? collect()
            : Customer::query()->whereIn('user_id', $userIds)->pluck('id', 'user_id');

        $pendingEmailKeys = $rows
            ->filter(fn ($row) => (int) ($row->pending_beneficiaries_count ?? 0) > 0)
            ->pluck('email_key')
            ->unique()
            ->values();

        $resendableByEmail = collect();
        if ($pendingEmailKeys->isNotEmpty()) {
            $resendableByEmail = CouponBeneficiary::query()
                ->whereIn('email_normalized', $pendingEmailKeys)
                ->where('status', CouponBeneficiaryStatus::PendingUser)
                ->whereNull('child_coupon_id')
                ->whereNull('user_id')
                ->whereNull('cancelled_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('email_normalized')
                ->map(function (Collection $group) {
                    $candidate = $group->first(fn (CouponBeneficiary $b) => $b->canResendInvitation())
                        ?? $group->first();

                    return $candidate ? [
                        'beneficiary_id' => $candidate->id,
                        'parent_coupon_id' => $candidate->parent_coupon_id,
                        'can_resend' => $candidate->canResendInvitation(),
                        'resend_available_at' => $candidate->resendInvitationAvailableAt()?->toIso8601String(),
                    ] : null;
                });
        }

        return $rows->map(function ($row) use ($customerIdsByUserId, $resendableByEmail) {
            $fullName = $this->resolveFullName($row);
            $lastAssignedAt = $row->last_assigned_at ?? $row->pending_last_assigned_at;
            $userId = $row->user_id !== null ? (int) $row->user_id : null;
            $customerId = $userId ? $customerIdsByUserId->get($userId) : null;

            return [
                'email_key' => $row->email_key,
                'email' => $row->email ?? $row->email_key,
                'user_id' => $userId,
                'full_name' => $fullName,
                'status' => $row->status,
                'assigned_coupons_count' => (int) $row->assigned_coupons_count,
                'pending_beneficiaries_count' => (int) $row->pending_beneficiaries_count,
                'available_balance_cents' => (int) $row->available_balance_cents,
                'used_balance_cents' => (int) $row->used_balance_cents,
                'reversed_balance_cents' => (int) $row->reversed_balance_cents,
                'last_assigned_at' => $lastAssignedAt
                    ? Carbon::parse($lastAssignedAt)->toIso8601String()
                    : null,
                'last_used_at' => $row->last_used_at
                    ? Carbon::parse($row->last_used_at)->toIso8601String()
                    : null,
                'last_invitation_sent_at' => $row->last_invitation_sent_at
                    ? Carbon::parse($row->last_invitation_sent_at)->toIso8601String()
                    : null,
                'invitation_count' => (int) $row->invitation_count,
                'customer_admin_url' => $customerId
                    ? route('admin.customers.show', $customerId)
                    : null,
                'coupons_index_url' => route('admin.coupons.index', ['user_email' => $row->email ?? $row->email_key]),
                'resend_invitation' => $resendableByEmail->get($row->email_key),
            ];
        });
    }

    private function resolveFullName(object $row): ?string
    {
        if ($row->user_id !== null) {
            $parts = array_filter([
                $row->user_name,
                $row->user_paternal_lastname,
                $row->user_maternal_lastname,
            ]);

            $name = trim(implode(' ', $parts));

            return $name !== '' ? $name : null;
        }

        $parts = array_filter([
            $row->pending_first_name,
            $row->pending_paternal_lastname,
            $row->pending_maternal_lastname,
        ]);

        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : null;
    }
}
