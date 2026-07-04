<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Support\GDA\GdaPayloadSanitizer;
use DomainException;
use Illuminate\Support\Facades\Log;

class StoreGdaResultsPdfToStorageAction
{
    public function __construct(
        protected StoreLaboratoryResultPdfAction $storeLaboratoryResultPdfAction,
    ) {
    }

    public function execute(
        LaboratoryPurchase $laboratoryPurchase,
        string $base64,
        ?LaboratoryNotification $notification = null,
        bool $overwrite = false
    ): string {
        $normalizedBase64 = GdaPayloadSanitizer::stripDataUriPrefix(trim($base64));

        $pdfBinary = base64_decode($normalizedBase64, true);

        if ($pdfBinary === false) {
            throw new DomainException('GDA results PDF base64 is invalid.');
        }

        if (! str_starts_with($pdfBinary, '%PDF')) {
            throw new DomainException('GDA results payload is not a valid PDF.');
        }

        $path = $this->storeLaboratoryResultPdfAction->execute(
            $laboratoryPurchase,
            $pdfBinary,
            [
                'source' => 'gda',
                'notification_id' => $notification?->id,
            ],
            $overwrite
        );

        if ($notification) {
            $notification->update([
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'results_fetched_at' => now()->toISOString(),
                    'results_source' => 'storage',
                    'results_storage_path' => $path,
                ]),
            ]);
        }

        Log::info('GDA results PDF stored to storage', [
            'purchase_id' => $laboratoryPurchase->id,
            'notification_id' => $notification?->id,
            'path' => $path,
            'source' => 'gda',
        ]);

        return $path;
    }
}
