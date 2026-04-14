<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildUserAdminChartDataAction;
use App\Enums\MonitoringCartType;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\LaboratoryNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request, BuildUserAdminChartDataAction $buildUserAdminChartDataAction)
    {
        $request->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $view = $request->get('view', 'list');

        $filters = collect($request->only([
            'search',
            'verified',
            'start_date',
            'end_date',
        ]))->filter()->all();

        $filters['view'] = $view;

        $query = User::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('paternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('maternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
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

        $chart = null;
        if ($view === 'chart') {
            $start = $request->filled('start_date')
                ? Carbon::parse($request->start_date, 'America/Monterrey')->startOfDay()
                : null;
            $end = $request->filled('end_date')
                ? Carbon::parse($request->end_date, 'America/Monterrey')->endOfDay()
                : null;
            $chart = $buildUserAdminChartDataAction($start, $end);

            if (! $request->filled('start_date')) {
                $filters['start_date'] = $chart['startDate'];
            }
            if (! $request->filled('end_date')) {
                $filters['end_date'] = $chart['endDate'];
            }
        }

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $filters,
            'chart' => $chart,
        ]);
    }

    public function show(User $user)
    {
        request()->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $user->load([
            'pendingLaboratoryResults',
            'referrer',
            'referrals',
        ]);

        // Para admin: si el customer está soft-deleted, User::customer() no lo trae.
        // Usamos withTrashed para no perder direcciones / compras / etc.
        // Orden: el registro más reciente (por si hubiera más de un customer con el mismo user_id).
        $customer = Customer::withTrashed()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->with([
                'addresses',
                'contacts' => function ($query) {
                    $query->withTrashed();
                },
                'taxProfiles' => function ($query) {
                    $query->withTrashed();
                },
                'laboratoryPurchases' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
                },
                'onlinePharmacyPurchases' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
                },
                'medicalAttentionSubscriptions' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions']);
                },
            ])
            ->first();

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

        // Notificaciones: muchas llegan ligadas a la compra (laboratory_purchase_id) y pueden no traer user_id.
        // Para el detalle de usuario, traemos por:
        // - user_id = user.id
        // - email_recipient_id = user.id
        // - laboratoryPurchase.customer_id = customer.id (si existe)
        $labNotificationsQuery = LaboratoryNotification::query()
            ->with(['laboratoryPurchase', 'laboratoryQuote'])
            ->where(function ($q) use ($user, $customer) {
                $q->where('user_id', $user->id)
                    ->orWhere('email_recipient_id', $user->id);

                if ($customer) {
                    $q->orWhereHas('laboratoryPurchase', function ($p) use ($customer) {
                        $p->where('customer_id', $customer->id);
                    });
                }
            })
            ->latest();

        $labNotifications = $labNotificationsQuery->limit(25)->get();
        $unreadLabNotificationsCount = (clone $labNotificationsQuery)->whereNull('read_at')->count();

        $monitoringCarts = null;
        $canViewCartDetails = request()->user()->administrator->hasPermissionTo('view cart details');
        if (request()->user()->administrator->hasPermissionTo('view carts')) {
            $monitoringCarts = Cart::query()
                ->with('items')
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (Cart $cart) {
                    return [
                        'id' => $cart->id,
                        'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
                        'display_status' => $cart->displayStatus(),
                        'items_count' => $cart->items->count(),
                        'total_formatted' => formattedPrice((float) $cart->total),
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Admin/User', [
            'user' => $user,
            'customer' => $customer,
            'canViewTaxProfilesAdmin' => request()->user()->administrator->hasPermissionTo('tax-profiles.manage'),
            'efevooTokens' => $efevooTokens,
            'efevooTransactions' => $efevooTransactions,
            'laboratoryNotifications' => $labNotifications,
            'unreadLabNotificationsCount' => $unreadLabNotificationsCount,
            'monitoringCarts' => $monitoringCarts,
            'canViewCartDetails' => $canViewCartDetails,
        ]);
    }
}
