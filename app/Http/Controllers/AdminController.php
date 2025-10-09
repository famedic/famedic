<?php

namespace App\Http\Controllers;

use App\Actions\BuildDailyChartDataAction;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function __invoke(Request $request, BuildDailyChartDataAction $buildLaboratoryDailyChartDataAction)
    {
        $start = now()->timezone('America/Monterrey')->subDays(15)->startOfDay();
        $end = now()->timezone('America/Monterrey')->endOfDay();

        $laboratoryDailyChart = $buildLaboratoryDailyChartDataAction(LaboratoryPurchase::filter(['start_date' => $start, 'end_date' => $end])->get());
        $onlinePharmacyDailyChart = $buildLaboratoryDailyChartDataAction(OnlinePharmacyPurchase::filter(['start_date' => $start, 'end_date' => $end])->get());

        // For medical attention subscriptions, we need to map price_cents to total_cents since BuildDailyChartDataAction expects total_cents
        $medicalAttentionSubscriptions = MedicalAttentionSubscription::whereBetween('created_at', [$start, $end])
            ->get()
            ->map(function ($subscription) {
                $subscription->total_cents = $subscription->price_cents;
                return $subscription;
            });
        $medicalAttentionDailyChart = $buildLaboratoryDailyChartDataAction($medicalAttentionSubscriptions);

        return Inertia::render('Admin/Admin', [
            'dateRange' => $start->isoFormat('MMM D') . ' a ' . $end->isoFormat('MMM D'),
            'laboratory' => $laboratoryDailyChart,
            'onlinePharmacy' => $onlinePharmacyDailyChart,
            'medicalAttention' => $medicalAttentionDailyChart,
        ]);
    }
}
