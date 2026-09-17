<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryResultEventType;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultEvent;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AnalyzeLegacyLaboratoryResultsAction
{
    public function __construct(
        private RecordGdaResultPdfVersionAction $recordGdaResultPdfVersionAction,
    ) {}

    public function execute(LaboratoryPurchase $purchase): void
    {
        if ($purchase->laboratoryResultStatuses()->exists()) {
            return;
        }

        $pdfBinary = $this->resolvePdfBinary($purchase);
        $path = $purchase->results ?: 'legacy-results-notification-'.$purchase->id.'.pdf';
        $notification = LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );

        $this->recordGdaResultPdfVersionAction->execute(
            $purchase->fresh('laboratoryPurchaseItems'),
            $pdfBinary,
            $path,
            $notification,
            source: 'legacy_existing_pdf',
            attemptRelease: false,
        );

        $status = $purchase->fresh('laboratoryResultStatuses')->laboratoryResultStatuses()->oldest('id')->first();

        if ($status) {
            LaboratoryResultEvent::query()->create([
                'laboratory_result_status_id' => $status->id,
                'event_type' => LaboratoryResultEventType::LegacyAnalysisRequested,
                'metadata' => [
                    'purchase_id' => $purchase->id,
                    'source' => 'existing_pdf',
                ],
                'created_at' => now(),
            ]);
        }
    }

    private function resolvePdfBinary(LaboratoryPurchase $purchase): string
    {
        if (filled($purchase->results) && Storage::exists($purchase->results)) {
            return Storage::get($purchase->results);
        }

        $notification = LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );

        if ($notification?->results_pdf_base64) {
            $binary = base64_decode($notification->results_pdf_base64, true);

            if ($binary !== false) {
                return $binary;
            }
        }

        throw new RuntimeException('No hay un PDF histórico disponible para analizar.');
    }
}
