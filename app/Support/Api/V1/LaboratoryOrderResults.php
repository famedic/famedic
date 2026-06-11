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

        $payload = [
            'order_id' => $purchase->id,
            'status' => $status,
            'results_available' => true,
        ];

        if (! empty($purchase->results)) {
            $payload['has_pdf'] = true;
            $payload['download_url'] = self::manualDownloadUrl($purchase);

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
        ];

        $payload['results'] = [$resultEntry];
        $payload['has_pdf'] = $resultEntry['has_pdf'];
        $payload['download_url'] = $resultEntry['download_url'];

        return $payload;
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
