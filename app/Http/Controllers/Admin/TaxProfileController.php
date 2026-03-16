<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TaxProfileController extends Controller
{
    public function index(Request $request)
    {
        $filters = collect($request->only([
            'search',
        ]))->filter()->all();

        $query = Customer::with(['user'])
            ->withCount('taxProfiles')
            ->whereHas('taxProfiles')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                })->orWhereHas('taxProfiles', function ($q) use ($search) {
                    $q->where('razon_social', 'like', '%' . $search . '%')
                        ->orWhere('rfc', 'like', '%' . $search . '%');
                });
            })
            ->orderByDesc('created_at');

        $customers = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/TaxProfiles', [
            'customers' => $customers,
            'filters' => $filters,
        ]);
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'user',
            'taxProfiles' => function ($query) {
                $query->latest();
            },
        ]);

        return Inertia::render('Admin/TaxProfile', [
            'customer' => $customer,
        ]);
    }
}

