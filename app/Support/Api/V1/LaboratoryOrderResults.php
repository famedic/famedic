<?php

namespace App\Support\Api\V1;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Carbon;

class LaboratoryOrderResults
{
    public static function hasPdf(LaboratoryPurchase $purchase): bool
    {
        if (! empty($purchase->results)) {
            return true;
        }

        $notification = self::latestResultsNotification($purchase);

        return $notification !== null && ! empty($notification->results_pdf_base64);
    }

    public static function availableAt(LaboratoryPurchase $purchase): ?Carbon
    {
        if (! empty($purchase->results)) {
            return $purchase->updated_at;
        }

        return self::latestResultsNotification($purchase)?->results_received_at;
    }

    public static function manualDownloadUrl(LaboratoryPurchase $purchase): ?string
    {
        if (empty($purchase->results)) {
            return null;
        }

        return route('laboratory-purchases.results', ['laboratory_purchase' => $purchase->id]);
    }

    public static function apiDownloadUrl(LaboratoryPurchase $purchase): ?string
    {
        $notification = self::latestResultsNotification($purchase);

        if ($notification === null || $notification->results_received_at === null) {
            return null;
        }

        return route('laboratory-results.download', ['type' => 'purchase', 'id' => $purchase->id]);
    }

    public static function downloadUrl(LaboratoryPurchase $purchase): ?string
    {
        if (! empty($purchase->results)) {
            return self::manualDownloadUrl($purchase);
        }

        return self::apiDownloadUrl($purchase);
    }

    public static function buildDetailPayload(LaboratoryPurchase $purchase): array
    {
        $status = LaboratoryOrderStatus::resolve($purchase);
        $downloadSupport = app(OrderDocumentDownloadSupport::class);

        $payload = [
            'order_id' => $purchase->id,
            'status' => $status,
            'results_available' => true,
        ];

        if (! empty($purchase->results)) {
            $payload['has_pdf'] = true;
            $payload['download_url'] = self::manualDownloadUrl($purchase);
            $payload['download'] = [
                'type' => 'bearer',
                'url' => $downloadSupport->resultBearerDownloadUrl($purchase),
            ];
            self::appendSecureAccessMetadata($payload);

            return $payload;
        }

        $notification = self::latestResultsNotification($purchase);

        if ($notification === null) {
            return $payload;
        }

        $resultEntry = [
            'id' => $notification->id,
            'name' => LaboratoryOrderStatus::formatStudyName($purchase),
            'available_at' => $notification->results_received_at?->toIso8601String(),
            'download_url' => self::apiDownloadUrl($purchase),
            'has_pdf' => ! empty($notification->results_pdf_base64),
            'download' => [
                'type' => 'bearer',
                'url' => $downloadSupport->resultBearerDownloadUrl($purchase),
            ],
        ];

        $payload['results'] = [$resultEntry];
        $payload['has_pdf'] = $resultEntry['has_pdf'];
        $payload['download_url'] = $resultEntry['download_url'];
        $payload['download'] = $resultEntry['download'];
        self::appendSecureAccessMetadata($payload);

        return $payload;
    }

    /**
     * Additive metadata when P0-B flags are ON. Does not remove download.url / download_url.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function appendSecureAccessMetadata(array &$payload): void
    {
        if ((bool) config('otp.p0a.flags.step_up_results_enabled', false)) {
            $payload['requires_step_up'] = true;
        }

        if ((bool) config('otp.p0a.flags.secure_links_results_enabled', false)) {
            $payload['secure_link_supported'] = true;
        }

        // P0-B4: nest requires_step_up under download when Bearer enforcement is active.
        if (\App\Services\Otp\StepUp\BearerStepUpEnforcement::isResultsEnforcementEnabled()
            && isset($payload['download']) && is_array($payload['download'])
        ) {
            $payload['download']['requires_step_up'] = true;
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            foreach ($payload['results'] as $i => $entry) {
                if (! is_array($entry) || ! isset($entry['download']) || ! is_array($entry['download'])) {
                    continue;
                }
                if (\App\Services\Otp\StepUp\BearerStepUpEnforcement::isResultsEnforcementEnabled()) {
                    $payload['results'][$i]['download']['requires_step_up'] = true;
                }
            }
        }
    }

    public static function latestResultsNotification(LaboratoryPurchase $purchase): ?LaboratoryNotification
    {
        return LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo !== null ? (string) $purchase->gda_consecutivo : null,
        );
    }
}
