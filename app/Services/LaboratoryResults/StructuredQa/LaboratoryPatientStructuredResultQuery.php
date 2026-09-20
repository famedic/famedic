<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultReport;
use Illuminate\Support\Collection;

/**
 * Consulta mínima de visibilidad paciente sobre resultados estructurados publicados.
 * Reutiliza activePublished(); no expone Shadow QA.
 */
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
            ->with(['observations.analyte', 'resultVersion'])
            ->get();
    }

    public function purchaseHasActivePublishedReport(LaboratoryPurchase $purchase, int $reportId): bool
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereKey($reportId)
            ->activePublished()
            ->exists();
    }
}
