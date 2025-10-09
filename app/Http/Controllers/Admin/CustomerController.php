<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildDailyCountChartDataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\IndexCustomerRequest;
use App\Http\Requests\Admin\Customers\ShowCustomerRequest;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use Carbon\Carbon;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(IndexCustomerRequest $request, BuildDailyCountChartDataAction $buildDailyCountChartDataAction)
    {
        $filters = collect($request->safe()->only(
            'search',
            'type',
            'medical_attention_status',
            'referral_status',
            'verification_status',
            'start_date',
            'end_date'
        ))->filter()->all();

        $customersQuery = Customer::with(['user.referrer', 'customerable'])
            ->withCount([
                'laboratoryPurchases',
                'onlinePharmacyPurchases',
                'medicalAttentionSubscriptions',
                'familyAccounts',
            ])
            ->withSum('laboratoryPurchases', 'total_cents')
            ->withSum('onlinePharmacyPurchases', 'total_cents')
            ->filter($filters);

        $customersForChart = (clone $customersQuery)->get();

        $customersDailyChart = $buildDailyCountChartDataAction(
            $customersForChart,
            ! empty($filters['start_date']) ? Carbon::parse($filters['start_date'], 'America/Monterrey') : null,
            ! empty($filters['end_date']) ? Carbon::parse($filters['end_date'], 'America/Monterrey') : null
        );

        $customers = $customersQuery
            ->latest()
            ->paginate()
            ->withQueryString();

        $customers->getCollection()->each(function ($customer) {
            if ($customer->customerable_type === OdessaAfiliateAccount::class) {
                $customer->customerable->load('odessaAfiliatedCompany');
            } elseif ($customer->customerable_type === FamilyAccount::class) {
                $customer->customerable->load('parentCustomer.user');
            }
        });

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
            'chart' => $customersDailyChart,
            'filters' => $filters,
            'canExport' => $request->user()->administrator->hasPermissionTo('customers.manage.export'),
        ]);
    }

    public function show(ShowCustomerRequest $request, Customer $customer)
    {
        $customer->load(['user.referrer', 'customerable', 'familyMembers.customer.user']);

        // Load additional relationships based on account type
        if ($customer->customerable_type === 'App\\Models\\OdessaAfiliateAccount' && $customer->customerable) {
            $customer->customerable->load('odessaAfiliatedCompany');
        } elseif ($customer->customerable_type === 'App\\Models\\FamilyAccount' && $customer->customerable) {
            $customer->customerable->load('parentCustomer.user');
        }

        // Add purchase counts for delete eligibility and statistics
        $customer->loadCount([
            'laboratoryPurchases',
            'onlinePharmacyPurchases',
            'medicalAttentionSubscriptions',
        ]);

        return Inertia::render('Admin/Customer', [
            'customer' => $customer,
            'laboratoryPurchases' => $customer->laboratoryPurchases()
                ->with(['transactions', 'vendorPayments', 'laboratoryPurchaseItems', 'invoice', 'invoiceRequest', 'devAssistanceRequests'])
                ->latest()
                ->paginate(5, ['*'], 'lab_page'),
            'onlinePharmacyPurchases' => $customer->onlinePharmacyPurchases()
                ->with(['transactions', 'vendorPayments', 'onlinePharmacyPurchaseItems', 'invoice', 'invoiceRequest', 'devAssistanceRequests'])
                ->latest()
                ->paginate(5, ['*'], 'pharmacy_page'),
            'medicalAttentionSubscriptions' => $customer->medicalAttentionSubscriptions()
                ->with(['transactions', 'customer'])
                ->latest()
                ->paginate(5, ['*'], 'medical_attention_subscriptions_page'),
        ]);
    }
}
