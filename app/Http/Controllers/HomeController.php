<?php

namespace App\Http\Controllers;

use App\Actions\Users\GenerateInvitationUrlAction;
use App\Models\EfevooToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __invoke(Request $request, GenerateInvitationUrlAction $generateInvitationUrlAction)
    {
        $invitationUrl = null;
        $user = null;
        $stats = null;
        $recentResults = null;

        if ($request->user()) {
            $invitationUrl = $generateInvitationUrlAction($request->user());
            $user = $request->user();

            $customer = $user->customer;

            if ($customer) {
                // Fecha de hace 30 días
                $thirtyDaysAgo = now()->subDays(30);

                // Obtener métodos de pago únicos (misma lógica que en PaymentMethodController)
                $tokens = EfevooToken::where('customer_id', $customer->id)
                    ->where('is_active', true)
                    ->orderByDesc('created_at')
                    ->get();

                // Evitar mostrar duplicados: una tarjeta por combinación últimos 4 dígitos + expiración
                $uniquePaymentMethods = $tokens->unique(function (EfevooToken $t) {
                    return $t->card_last_four.'-'.($t->card_expiration ?? '');
                })->values();

                $paymentMethodsCount = $uniquePaymentMethods->count();
                $hasPaymentMethods = $paymentMethodsCount > 0;

                // Obtener gastos mensuales (últimos 12 meses)
                $monthlySpending = collect();
                $maxSpending = 0;

                try {
                    $monthlyData = DB::table('laboratory_purchases')
                        ->select(
                            DB::raw('DATE_FORMAT(created_at, "%b") as month'),
                            DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month_key'),
                            DB::raw('ROUND(SUM(total_cents) / 100, 2) as amount')
                        )
                        ->where('customer_id', $customer->id)
                        ->where('created_at', '>=', now()->subMonths(12))
                        ->whereNull('deleted_at')
                        ->groupBy('month', 'month_key')
                        ->orderBy('month_key', 'asc')
                        ->get();

                    $monthlySpending = $monthlyData->map(function ($item) {
                        return [
                            'month' => $item->month,
                            'amount' => (float) $item->amount,
                        ];
                    });

                    $maxSpending = $monthlySpending->max('amount') ?: 0;

                } catch (\Exception $e) {
                    \Log::error('Error al calcular gastos mensuales: '.$e->getMessage());
                }

                // Calcular total histórico (TODAS las compras)
                $totalHistoricalCents = $customer->laboratoryPurchases()->sum('total_cents');
                $totalHistorical = $totalHistoricalCents / 100;

                // Calcular total de últimos 12 meses
                $totalLast12MonthsCents = $customer->laboratoryPurchases()
                    ->where('created_at', '>=', now()->subMonths(12))
                    ->sum('total_cents');
                $totalLast12Months = $totalLast12MonthsCents / 100;

                // Calcular total de últimos 30 días
                $totalLast30DaysCents = $customer->laboratoryPurchases()
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->sum('total_cents');
                $totalLast30Days = $totalLast30DaysCents / 100;

                // Calcular compras antiguas (más de 12 meses)
                $totalOldCents = $totalHistoricalCents - $totalLast12MonthsCents;
                $totalOld = $totalOldCents / 100;

                // Estadísticas completas
                $stats = [
                    // Datos básicos
                    'profileIsComplete' => $user->profile_is_complete,
                    'pendingResultsCount' => $user->pending_results_count,
                    'unreadNotificationsCount' => $user->unread_lab_notifications_count,

                    // Configuración - Métodos de pago (usando conteo único)
                    'hasPaymentMethods' => $hasPaymentMethods,
                    'paymentMethodsCount' => $paymentMethodsCount,

                    // Configuración - Direcciones
                    'hasAddresses' => $customer->addresses()->exists(),
                    'addressesCount' => $customer->addresses()->count(),

                    // Configuración - Perfiles fiscales
                    'hasTaxProfiles' => $customer->taxProfiles()->exists(),
                    'taxProfilesCount' => $customer->taxProfiles()->count(),

                    // Configuración - Contactos
                    'hasContacts' => $customer->contacts()->exists(),
                    'contactsCount' => $customer->contacts()->count(),

                    // Compras totales
                    'hasRecentPurchases' => $customer->laboratoryPurchases()->exists(),
                    'purchasesCount' => $customer->laboratoryPurchases()->count(),

                    // Compras en últimos 30 días
                    'recentPurchasesCount' => $customer->laboratoryPurchases()
                        ->where('created_at', '>=', $thirtyDaysAgo)
                        ->count(),

                    // Facturas totales
                    'hasInvoices' => $customer->laboratoryPurchases()->whereHas('invoice')->exists(),
                    'invoicesCount' => $customer->laboratoryPurchases()->whereHas('invoice')->count(),

                    // Facturas en últimos 30 días
                    'recentInvoicesCount' => $customer->laboratoryPurchases()
                        ->where('created_at', '>=', $thirtyDaysAgo)
                        ->whereHas('invoice')
                        ->count(),

                    // Suscripciones médicas
                    'hasMedicalSubscription' => $customer->medicalAttentionSubscriptions()
                        ->where('end_date', '>=', now())
                        ->exists(),

                    // DATOS FINANCIEROS CON DESGLOSE CLARO
                    'financialSummary' => [
                        'totalHistorical' => $totalHistorical,
                        'totalLast12Months' => $totalLast12Months,
                        'totalLast30Days' => $totalLast30Days,
                        'totalOld' => $totalOld,
                        'hasOldPurchases' => $totalOld > 0,
                    ],

                    // Gastos mensuales (últimos 12 meses)
                    'monthlySpending' => $monthlySpending,
                    'maxSpending' => $maxSpending,
                ];

                // Resultados recientes (últimos 30 días)
                $recentResults = $customer->laboratoryPurchases()
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->whereHas('laboratoryNotifications', function ($query) {
                        $query->whereNotNull('results_pdf_base64');
                    })
                    ->with(['laboratoryPurchaseItems', 'laboratoryNotifications' => function ($query) {
                        $query->whereNotNull('results_pdf_base64')->latest();
                    }])
                    ->get()
                    ->map(function ($purchase) {
                        $notification = $purchase->laboratoryNotifications->first();

                        return [
                            'name' => $purchase->laboratoryPurchaseItems->first()?->name ?? 'Estudio',
                            'date' => $notification?->created_at?->format('d/m/Y') ?? $purchase->created_at->format('d/m/Y'),
                            'id' => $purchase->id,
                            'has_results' => $notification ? true : false,
                        ];
                    });
            }
        }

        // No pasar `auth` aquí: HandleInertiaRequests ya comparte el usuario completo
        // (profile_photo_url, appends, etc.). Sobrescribir `auth` rompe el avatar y el menú.
        return Inertia::render('Home', [
            'invitationUrl' => $invitationUrl,
            'userStats' => $stats,
            'recentResults' => $recentResults,
        ]);
    }
}
