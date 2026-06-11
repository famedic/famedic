<?php

namespace App\Support\Api\V1;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Collection;

class LaboratoryOrderStatus
{
    public static function resolve(LaboratoryPurchase $purchase): string
    {
        if ($purchase->trashed()) {
            return 'cancelled';
        }

        if (self::hasResults($purchase)) {
            return 'results_ready';
        }

        if ($purchase->hasSampleCollected()) {
            return 'sample_taken';
        }

        return 'in_progress';
    }

    public static function hasResults(LaboratoryPurchase $purchase): bool
    {
        if (! empty($purchase->results)) {
            return true;
        }

        $resultsNotif = $purchase->resultsNotification()->first();

        return $resultsNotif !== null && $resultsNotif->results_received_at !== null;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'in_progress' => 'En proceso',
            'sample_taken' => 'Muestra tomada',
            'results_ready' => 'Resultados listos',
            'cancelled' => 'Cancelado',
            default => $status,
        };
    }

    public static function formatStudyName(LaboratoryPurchase $purchase): string
    {
        /** @var Collection<int, mixed> $items */
        $items = $purchase->relationLoaded('laboratoryPurchaseItems')
            ? $purchase->laboratoryPurchaseItems
            : collect();

        if ($items->isEmpty()) {
            return 'Estudio de laboratorio';
        }

        if ($items->count() === 1) {
            return $items->first()->name ?? 'Estudio';
        }

        $names = $items->take(2)->pluck('name')->filter()->values();
        $extra = $items->count() > 2 ? ' y otros' : '';

        return $names->join(', ').$extra;
    }
}
