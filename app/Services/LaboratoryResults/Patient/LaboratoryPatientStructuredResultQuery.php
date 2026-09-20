<?php

namespace App\Services\LaboratoryResults\Patient;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class LaboratoryPatientStructuredResultQuery
{
    /**
     * @return Collection<int, LaboratoryResultReport>
     */
    public function activePublishedReportsForPurchase(LaboratoryPurchase $purchase): Collection
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->activePublished()
            ->with([
                'resultVersion',
                'observations' => fn (HasMany $query) => $this->applyObservationOrdering($query),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
    }

    public function activePublishedReportForPurchase(LaboratoryPurchase $purchase): ?LaboratoryResultReport
    {
        return $this->activePublishedReportsForPurchase($purchase)->first();
    }

    public function purchaseHasActivePublishedReport(LaboratoryPurchase $purchase, int $reportId): bool
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereKey($reportId)
            ->activePublished()
            ->exists();
    }

    /**
     * @param  Builder<LaboratoryResultObservation>|HasMany<LaboratoryResultObservation>  $query
     */
    private function applyObservationOrdering(Builder|HasMany $query): Builder|HasMany
    {
        return $query
            ->orderByRaw('panel_name_raw IS NULL')
            ->orderBy('panel_name_raw')
            ->orderByRaw('laboratory_purchase_item_id IS NULL')
            ->orderBy('laboratory_purchase_item_id')
            ->orderByRaw('source_page IS NULL')
            ->orderBy('source_page')
            ->orderBy('id');
    }
}
