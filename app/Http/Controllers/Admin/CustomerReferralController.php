<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\IndexCustomerReferralRequest;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;

class CustomerReferralController extends Controller
{
    public function index(IndexCustomerReferralRequest $request)
    {
        $filters = collect($request->safe()->only('search', 'start_date', 'end_date'))->filter()->all();

        $referralDateFilter = function ($query) use ($filters) {
            $this->applyReferralDateFilters($query, $filters);
        };

        $invitersQuery = User::query()
            ->whereHas('referrals', $referralDateFilter)
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('paternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('maternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->withCount(['referrals' => $referralDateFilter])
            ->with([
                'customer.user',
                'customer.customerable',
                'referrals' => function ($query) use ($referralDateFilter) {
                    $referralDateFilter($query);
                    $query->with(['customer.user', 'customer.customerable'])->latest();
                },
            ])
            ->orderByDesc('referrals_count')
            ->orderByDesc('id');

        $totalReferralsQuery = User::query()->whereNotNull('referred_by');
        $referralDateFilter($totalReferralsQuery);
        $totalReferrals = $totalReferralsQuery->count();

        $inviters = $invitersQuery
            ->paginate(15)
            ->withQueryString();

        $inviters->getCollection()->each(function (User $inviter) {
            if ($inviter->customer) {
                $this->loadCustomerableRelations($inviter->customer);
            }

            $inviter->referrals->each(function (User $referral) {
                if ($referral->customer) {
                    $this->loadCustomerableRelations($referral->customer);
                }
            });
        });

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/CustomerReferrals', [
            'inviters' => $inviters,
            'filters' => $filters,
            'summary' => [
                'inviters_count' => $inviters->total(),
                'referrals_count' => $totalReferrals,
            ],
        ]);
    }

    private function applyReferralDateFilters($query, array $filters): void
    {
        if (! empty($filters['start_date'])) {
            $query->where(
                'created_at',
                '>=',
                Carbon::parse($filters['start_date'], 'America/Monterrey')->startOfDay()
            );
        }

        if (! empty($filters['end_date'])) {
            $query->where(
                'created_at',
                '<=',
                Carbon::parse($filters['end_date'], 'America/Monterrey')->endOfDay()
            );
        }
    }

    private function loadCustomerableRelations($customer): void
    {
        if ($customer->customerable_type === OdessaAfiliateAccount::class) {
            $customer->customerable?->load('odessaAfiliatedCompany');
        } elseif ($customer->customerable_type === FamilyAccount::class) {
            $customer->customerable?->load('parentCustomer.user');
        }
    }
}
