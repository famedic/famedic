<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentAttemptController extends Controller
{
    public function index(Request $request)
    {
        $filters = collect($request->only([
            'search',
            'gateway',
            'status',
        ]))->filter()->all();

        $query = PaymentAttempt::query()
            ->with(['customer.user'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'like', '%' . $search . '%')
                        ->orWhere('processor_transaction_id', 'like', '%' . $search . '%')
                        ->orWhere('processor_code', 'like', '%' . $search . '%');
                })->orWhereHas('customer.user', function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['gateway'] ?? null, function ($query, string $gateway) {
                $query->where('gateway', $gateway);
            })
            ->when($filters['status'] ?? null, function ($query, string $status) {
                $query->where('status', $status);
            })
            ->orderByDesc('processed_at')
            ->orderByDesc('created_at');

        $attempts = $query->paginate(25)->withQueryString();

        $gateways = PaymentAttempt::select('gateway')
            ->whereNotNull('gateway')
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway');

        $statuses = PaymentAttempt::select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        return Inertia::render('Admin/PaymentAttempts', [
            'attempts' => $attempts,
            'filters' => $filters,
            'gateways' => $gateways,
            'statuses' => $statuses,
        ]);
    }

    public function show(PaymentAttempt $paymentAttempt)
    {
        $paymentAttempt->load(['customer.user']);

        return Inertia::render('Admin/PaymentAttempt', [
            'attempt' => $paymentAttempt,
        ]);
    }
}

