<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MedicalAttentionSubscriptions\IndexMedicalAttentionSubscriptionRequest;
use App\Http\Requests\Admin\MedicalAttentionSubscriptions\ShowMedicalAttentionSubscriptionRequest;
use App\Models\FamilyAccount;
use App\Models\MedicalAttentionSubscription;
use Carbon\Carbon;
use Inertia\Inertia;

class MedicalAttentionSubscriptionController extends Controller
{
    public function index(IndexMedicalAttentionSubscriptionRequest $request)
    {
        $filters = collect($request->only(
            'search',
            'status',
            'start_date',
            'end_date',
            'payment_method'
        ))->filter()->all();

        $subscriptions = MedicalAttentionSubscription::with([
            'customer.user',
            'customer.customerable',
            'customer.familyMembers',
            'transactions',
        ])
            ->filter($filters)
            ->latest()
            ->paginate()
            ->withQueryString();

        $subscriptions->getCollection()->each(function ($subscription) {
            if ($subscription->customer->customerable_type === FamilyAccount::class) {
                $subscription->customer->customerable->load('parentCustomer.user');
            }
        });

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('D MMM Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('D MMM Y');
        }

        return Inertia::render('Admin/MedicalAttentionSubscriptions', [
            'subscriptions' => $subscriptions,
            'filters' => $filters,
            'canExport' => $request->user()->administrator->hasPermissionTo('medical-attention-subscriptions.manage.export'),
        ]);
    }

    public function show(ShowMedicalAttentionSubscriptionRequest $request, MedicalAttentionSubscription $medicalAttentionSubscription)
    {
        $medicalAttentionSubscription->load([
            'customer.user',
            'customer.customerable',
            'customer.familyMembers.customer.user',
            'transactions',
        ]);

        // Load additional relationships based on account type
        if ($medicalAttentionSubscription->customer->customerable_type === 'App\\Models\\FamilyAccount' && $medicalAttentionSubscription->customer->customerable) {
            $medicalAttentionSubscription->customer->customerable->load('parentCustomer.user');
        }

        return Inertia::render('Admin/MedicalAttentionSubscription', [
            'subscription' => $medicalAttentionSubscription,
        ]);
    }
}
