<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = collect($request->only([
            'search',
            'verified',
        ]))->filter()->all();

        $query = User::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['verified'] ?? null, function ($query, string $verified) {
                if ($verified === 'verified') {
                    $query->whereNotNull('email_verified_at')
                        ->whereNotNull('phone_verified_at');
                } elseif ($verified === 'unverified') {
                    $query->where(function ($q) {
                        $q->whereNull('email_verified_at')
                            ->orWhereNull('phone_verified_at');
                    });
                }
            })
            ->orderByDesc('created_at');

        $users = $query
            ->withCount(['referrals'])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function show(User $user)
    {
        $user->load([
            'customer.addresses',
            'customer.contacts',
            'customer.taxProfiles',
            'customer.laboratoryPurchases' => function ($query) {
                $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
            },
            'customer.onlinePharmacyPurchases' => function ($query) {
                $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
            },
            'customer.medicalAttentionSubscriptions' => function ($query) {
                $query->latest()->limit(10)->with(['transactions']);
            },
            'pendingLaboratoryResults',
            'laboratoryNotifications' => function ($query) {
                $query->latest()->limit(20);
            },
            'unreadLaboratoryNotifications',
            'referrer',
            'referrals',
        ]);

        $customer = $user->customer;

        $efevooTokens = collect();
        $efevooTransactions = collect();

        if ($customer) {
            $efevooTokens = EfevooToken::byCustomer($customer->id)
                ->withCount('transactions')
                ->get();

            if ($efevooTokens->isNotEmpty()) {
                $efevooTransactions = EfevooTransaction::whereIn('efevoo_token_id', $efevooTokens->pluck('id'))
                    ->latest()
                    ->limit(20)
                    ->get();
            }
        }

        return Inertia::render('Admin/User', [
            'user' => $user,
            'customer' => $customer,
            'efevooTokens' => $efevooTokens,
            'efevooTransactions' => $efevooTransactions,
        ]);
    }
}

