<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Support\GDA\GdaPayloadSanitizer;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoreGdaResultsPdfToStorageAction
{
    public function __construct(
        protected StoreLaboratoryResultPdfAction $storeLaboratoryResultPdfAction,
        protected RecordGdaResultPdfVersionAction $recordGdaResultPdfVersionAction,
    ) {}

    public function execute(
        LaboratoryPurchase $laboratoryPurchase,
        string $base64,
        ?LaboratoryNotification $notification = null,
        bool $overwrite = false,
        bool $preserveExisting = false,
        bool $updatePurchaseResults = true,
        bool $strictClassification = false,
    ): string {
        $normalizedBase64 = GdaPayloadSanitizer::stripDataUriPrefix(trim($base64));

        $pdfBinary = base64_decode($normalizedBase64, true);

        if ($pdfBinary === false) {
            throw new DomainException('GDA results PDF base64 is invalid.');
        }

        $maxBytes = (int) config('laboratory-results.max_pdf_bytes', 25 * 1024 * 1024);
        if ($maxBytes > 0 && strlen($pdfBinary) > $maxBytes) {
            throw new DomainException('GDA results PDF exceeds the configured maximum size.');
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
                'preserve_existing' => $preserveExisting,
                'update_purchase_results' => $updatePurchaseResults,
            ],
            $overwrite
        );

        if (! Storage::exists($path)) {
            throw new RuntimeException('No se pudo confirmar el archivo PDF en storage.');
        }

        try {
            $this->recordGdaResultPdfVersionAction->execute(
                $laboratoryPurchase->fresh('laboratoryPurchaseItems'),
                $pdfBinary,
                $path,
                $notification,
                source: 'gda'
            );
        } catch (Throwable $exception) {
            Log::warning('GDA results PDF classification failed after storage', [
                'purchase_id' => $laboratoryPurchase->id,
                'notification_id' => $notification?->id,
                'path' => $path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ($strictClassification) {
                throw $exception;
            }
        }

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
