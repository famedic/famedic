<?php

namespace App\Http\Controllers;

use App\Actions\BuildDailyChartDataAction;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Services\AdminDashboardPaymentMethodMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function __invoke(
        Request $request,
        BuildDailyChartDataAction $buildLaboratoryDailyChartDataAction,
        AdminDashboardPaymentMethodMetrics $paymentMethodMetrics
    ) {
        $start = now()->timezone('America/Monterrey')->subDays(15)->startOfDay();
        $end = now()->timezone('America/Monterrey')->endOfDay();

        $laboratoryPurchases = LaboratoryPurchase::with('transactions')
            ->filter(['start_date' => $start, 'end_date' => $end])
            ->get();
        $onlinePharmacyPurchases = OnlinePharmacyPurchase::with('transactions')
            ->filter(['start_date' => $start, 'end_date' => $end])
            ->get();

        $laboratoryDailyChart = $buildLaboratoryDailyChartDataAction($laboratoryPurchases);
        $onlinePharmacyDailyChart = $buildLaboratoryDailyChartDataAction($onlinePharmacyPurchases);

        // For medical attention subscriptions, we need to map price_cents to total_cents since BuildDailyChartDataAction expects total_cents
        $medicalAttentionSubscriptions = MedicalAttentionSubscription::with('transactions')
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->map(function ($subscription) {
                $subscription->total_cents = $subscription->price_cents;

                return $subscription;
            });
        $medicalAttentionDailyChart = $buildLaboratoryDailyChartDataAction($medicalAttentionSubscriptions);

        return Inertia::render('Admin/Admin', [
            'dateRange' => $start->isoFormat('MMM D').' a '.$end->isoFormat('MMM D'),
            'laboratory' => $laboratoryDailyChart,
            'onlinePharmacy' => $onlinePharmacyDailyChart,
            'medicalAttention' => $medicalAttentionDailyChart,
            'paymentMethods' => $paymentMethodMetrics->build(
                collect([
                    'laboratory' => $laboratoryPurchases,
                    'onlinePharmacy' => $onlinePharmacyPurchases,
                    'medicalAttention' => $medicalAttentionSubscriptions,
                ]),
                $start,
                $end,
            ),
        ]);
    }
}
