<?php

namespace App\Http\Controllers;

use App\Http\Resources\SharedLaboratoryPurchaseResource;
use App\Models\LaboratoryPurchaseShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        $relations = [
            'customer:id,user_id',
            'customer.user:id,name,paternal_lastname,maternal_lastname',
            'laboratoryPurchaseItems:id,laboratory_purchase_id,gda_id,name,indications,feature_list,price_cents',
            'laboratoryAppointment:id,laboratory_purchase_id,laboratory_store_id,appointment_date,confirmed_at',
            'laboratoryAppointment.laboratoryStore:id,name,address,google_maps_url,phone,weekly_hours,saturday_hours,sunday_hours',
        ];

        if (Schema::hasTable('laboratory_purchase_preparation_summaries')) {
            $relations[] = 'preparationSummary:id,laboratory_purchase_id,ai_execution_id,source_hash,status,summary_text,summary_json,generated_at,invalidated_at';
            $relations[] = 'preparationSummary.aiExecution:id,status,prompt_version';
        }

        $purchase->load($relations);

        $share->increment('views_count', 1, ['last_viewed_at' => now()]);

        Log::info('laboratory_purchase_share_opened', [
            'share_id' => $share->id,
            'laboratory_purchase_id' => $purchase->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
        ]);

        $metaTitle = 'Orden de compra de laboratorio | FAMEDIC';
        $metaDescription = 'Consulta la información de tu orden de laboratorio, estudios solicitados, cita e indicaciones de preparación.';
        $metaImage = asset('images/og/famedic-og.png');
        $currentUrl = $request->url();

        return Inertia::render('Shared/LaboratoryOrder', [
            'meta_title' => $metaTitle,
            'description' => $metaDescription,
            'og_type' => 'website',
            'og_site_name' => 'FAMEDIC',
            'og_url' => $currentUrl,
            'og_title' => $metaTitle,
            'og_description' => $metaDescription,
            'og_image' => $metaImage,
            'og_image_width' => '1200',
            'og_image_height' => '630',
            'og_image_alt' => 'FAMEDIC, salud al alcance de todos',
            'twitter_card' => 'summary_large_image',
            'twitter_url' => $currentUrl,
            'twitter_title' => $metaTitle,
            'twitter_description' => $metaDescription,
            'twitter_image' => $metaImage,
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
