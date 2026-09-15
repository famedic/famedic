<?php

namespace App\Http\Controllers;

use App\Http\Resources\SharedLaboratoryPurchaseResource;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SharedLaboratoryOrderController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        Inertia::setRootView('shared');

        $share = LaboratoryPurchaseShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->with(['laboratoryPurchase' => fn ($query) => $query->withTrashed()])
            ->first();

        if (! $share || ! $share->isActive()) {
            abort(404);
        }

        $purchase = $share->laboratoryPurchase;

        if (! $purchase || $purchase->trashed()) {
            abort(404);
        }

        $purchase->load([
            'customer:id,user_id',
            'customer.user:id,name,paternal_lastname,maternal_lastname',
            'laboratoryPurchaseItems:id,laboratory_purchase_id,gda_id,name,indications,feature_list,price_cents',
            'laboratoryAppointment:id,laboratory_purchase_id,laboratory_store_id,appointment_date,confirmed_at',
            'laboratoryAppointment.laboratoryStore:id,name,address,google_maps_url,phone,weekly_hours,saturday_hours,sunday_hours',
        ]);

        $share->increment('views_count', 1, ['last_viewed_at' => now()]);

        Log::info('laboratory_purchase_share_opened', [
            'share_id' => $share->id,
            'laboratory_purchase_id' => $purchase->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
        ]);

        return Inertia::render('Shared/LaboratoryOrder', [
            'auth' => ['user' => null],
            'activeCampaignSiteTracking' => ['enabled' => false, 'email' => null],
            'medicalAttentionSubscriptionIsActive' => null,
            'formattedMedicalAttentionSubscriptionExpiresAt' => null,
            'medicalAttentionIdentifier' => null,
            'hasOdessaAfiliateAccount' => null,
            'laboratoryCarts' => [],
            'onlinePharmacyCart' => [],
            'inAppNotificationFeed' => null,
            'userNavigation' => [],
            'share' => [
                'expires_at' => $share->expires_at?->toIso8601String(),
                'formatted_expires_at' => $share->expires_at
                    ? localizedDate($share->expires_at)->isoFormat('D MMM Y h:mm a')
                    : null,
            ],
            'laboratoryOrder' => (new SharedLaboratoryPurchaseResource($purchase))->toArray($request),
        ])->toResponse($request)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
