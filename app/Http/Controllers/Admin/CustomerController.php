<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Customers\BuildCustomerInteractionChartAction;
use App\Actions\Admin\Customers\BuildCustomerMonitorExtrasAction;
use App\Actions\BuildDailyCountChartDataAction;
use App\Data\StatesMexico;
use App\Enums\Gender;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\IndexCustomerRequest;
use App\Http\Requests\Admin\Customers\ShowCustomerRequest;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\FamilyAccount;
use App\Models\LaboratoryNotification;
use App\Models\OdessaAfiliateAccount;
use App\Models\PaymentAttempt;
use App\Services\ActiveCampaign\ActiveCampaignContactsService;
use App\Services\ActiveCampaign\ActiveCampaignMirrorService;
use App\Services\UserPurchases\PendingPurchasesQuery;
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

    public function show(
        ShowCustomerRequest $request,
        Customer $customer,
        PendingPurchasesQuery $pendingPurchasesQuery,
        BuildCustomerInteractionChartAction $buildCustomerInteractionChartAction,
        BuildCustomerMonitorExtrasAction $buildCustomerMonitorExtrasAction,
        ActiveCampaignContactsService $activeCampaignContactsService,
        ActiveCampaignMirrorService $activeCampaignMirrorService,
    ) {
        $customer->load([
            'user.referrer',
            'customerable',
            'familyMembers.customer.user',
            'addresses',
            'contacts' => function ($query) {
                $query->withTrashed();
            },
            'taxProfiles' => function ($query) {
                $query->withTrashed()
                    ->withExists([
                        'invoiceRequests as used_invoice_requests_exist' => function ($relationQuery) {
                            $relationQuery->withTrashed();
                        },
                    ]);
            },
            'laboratoryAppointments' => function ($query) {
                $query->with([
                    'laboratoryStore',
                    'laboratoryPurchase',
                    'interactions' => fn ($interactionQuery) => $interactionQuery->latest()->limit(5),
                ])
                    ->latest()
                    ->limit(8);
            },
            'activeCampaignWebActivities' => function ($query) {
                $query->latest('occurred_at')
                    ->latest()
                    ->limit(12);
            },
        ]);

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
            'taxProfiles',
            'addresses',
            'laboratoryAppointments',
            'activeCampaignWebActivities',
        ]);

        $user = $customer->user;
        $efevooTokens = EfevooToken::byCustomer($customer->id)
            ->withCount('transactions')
            ->get();
        $efevooTransactions = $efevooTokens->isNotEmpty()
            ? EfevooTransaction::whereIn('efevoo_token_id', $efevooTokens->pluck('id'))
                ->latest()
                ->limit(20)
                ->get()
            : collect();
        $paymentAttempts = PaymentAttempt::query()
            ->where('customer_id', $customer->id)
            ->latest('processed_at')
            ->latest()
            ->limit(20)
            ->get();

        $labNotificationsQuery = LaboratoryNotification::query()
            ->with(['laboratoryPurchase', 'laboratoryQuote'])
            ->where(function ($query) use ($customer, $user) {
                $query->whereHas('laboratoryPurchase', function ($purchase) use ($customer) {
                    $purchase->where('customer_id', $customer->id);
                });

                if ($user) {
                    $query->orWhere('user_id', $user->id)
                        ->orWhere('email_recipient_id', $user->id);
                }
            })
            ->latest();

        $labNotifications = $labNotificationsQuery->limit(25)->get();
        $unreadLabNotificationsCount = (clone $labNotificationsQuery)->whereNull('read_at')->count();

        $monitoringCarts = null;
        $canViewCartDetails = $request->user()->administrator->hasPermissionTo('view cart details');
        if ($user && $request->user()->administrator->hasPermissionTo('view carts')) {
            $monitoringCarts = Cart::query()
                ->with('items')
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (Cart $cart) {
                    $labBrands = $cart->labBrands();
                    $primaryBrand = $labBrands[0] ?? null;

                    return [
                        'id' => $cart->id,
                        'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
                        'brand' => $primaryBrand,
                        'display_status' => $cart->displayStatus(),
                        'display_status_label' => $cart->displayStatusLabel(),
                        'items_count' => $cart->items->count(),
                        'total_formatted' => formattedPrice((float) $cart->total),
                        'inactive_for_label' => $cart->inactiveForLabel(),
                        'updated_at' => $cart->updated_at,
                        'last_activity_at' => localizedDate($cart->lastUserActivityAt())?->isoFormat('D MMM Y h:mm a'),
                    ];
                })
                ->values()
                ->all();
        }

        $pendingPurchasesReadModel = $pendingPurchasesQuery->forCustomer($customer);
        $interactionMonitor = $buildCustomerInteractionChartAction($customer, $user);

        $monitorExtras = $user
            ? $buildCustomerMonitorExtrasAction(
                $user,
                $customer,
                $pendingPurchasesReadModel['pendingPurchases'],
                $monitoringCarts,
            )
            : [
                'pendingActivity' => $pendingPurchasesReadModel['pendingPurchases'],
                'notificationGroups' => [],
                'platformAccess' => null,
            ];

        $recentActivity = $interactionMonitor['recentActivity'];
        if ($monitorExtras['platformAccess']) {
            array_unshift($recentActivity, [
                'category' => 'access',
                'category_label' => 'Acceso',
                'label' => 'Último acceso a la plataforma',
                'at' => $monitorExtras['platformAccess']['last_access_at'],
                'detail' => $monitorExtras['platformAccess']['last_login_at']
                    ? 'Login: '.$monitorExtras['platformAccess']['last_login_at']
                    : 'Actividad de sesión',
            ]);
        }

        $canViewActiveCampaignHub = $request->user()->administrator->hasPermissionTo('activecampaign.manage');
        $activeCampaignMirror = $activeCampaignContactsService->buildMirrorPayloadForCustomer(
            $customer,
            $activeCampaignMirrorService,
        );
        $activeCampaignDispatches = ActiveCampaignDispatch::query()
            ->where('customer_id', $customer->id)
            ->latest('updated_at')
            ->latest()
            ->limit(15)
            ->get(['id', 'event_type', 'status', 'synced_at', 'last_error', 'updated_at', 'created_at']);
        $activeCampaignContact = Contact::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first(['id']);
        $activeCampaignContactUrl = $canViewActiveCampaignHub && $activeCampaignContact
            ? route('admin.activecampaign.contacts', ['drawer_contact_id' => $activeCampaignContact->id])
            : null;

        return Inertia::render('Admin/Customer', [
            'customer' => $customer,
            'genders' => Gender::casesWithLabels(),
            'states' => StatesMexico::todos(),
            'canManageUser' => $user && $request->user()->administrator->hasPermissionTo('users.manage'),
            'canUpdatePassword' => (bool) $request->user()->administrator?->hasRole('superadmin'),
            'canViewTaxProfilesAdmin' => $request->user()->administrator->hasPermissionTo('tax-profiles.manage'),
            'canViewPaymentAttempts' => $request->user()->administrator->hasPermissionTo('payment-attempts.manage'),
            'efevooTokens' => $efevooTokens,
            'efevooTransactions' => $efevooTransactions,
            'paymentAttempts' => $paymentAttempts,
            'laboratoryNotifications' => $labNotifications,
            'unreadLabNotificationsCount' => $unreadLabNotificationsCount,
            'monitoringCarts' => $monitoringCarts,
            'canViewCartDetails' => $canViewCartDetails,
            'pendingPurchases' => $pendingPurchasesReadModel['pendingPurchases'],
            'pendingPurchasesSummary' => $pendingPurchasesReadModel['summary'],
            'pendingActivity' => $monitorExtras['pendingActivity'],
            'notificationGroups' => $monitorExtras['notificationGroups'],
            'platformAccess' => $monitorExtras['platformAccess'],
            'interactionSummary' => $interactionMonitor['summary'],
            'recentActivity' => $recentActivity,
            'activeCampaignMirror' => $activeCampaignMirror,
            'activeCampaignDispatches' => $activeCampaignDispatches,
            'activeCampaignContactUrl' => $activeCampaignContactUrl,
            'canViewActiveCampaignHub' => $canViewActiveCampaignHub,
            'taxRegimes' => config('taxregimes.regimes'),
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
