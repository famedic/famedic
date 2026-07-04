<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StoreLaboratoryResultPdfAction
{
    public function execute(
        LaboratoryPurchase $laboratoryPurchase,
        string $pdfBinary,
        array $metadata = [],
        bool $overwrite = false
    ): string {
        if (
            ! $overwrite
            && ! empty($laboratoryPurchase->results)
            && Storage::exists($laboratoryPurchase->results)
        ) {
            Log::warning('GDA results PDF not stored because purchase already has results', [
                'purchase_id' => $laboratoryPurchase->id,
                'existing_results' => $laboratoryPurchase->results,
            ]);

            return $laboratoryPurchase->results;
        }

        $path = $metadata['path'] ?? sprintf(
            'results/gda-%d-%s.pdf',
            $laboratoryPurchase->id,
            substr(hash('sha256', $pdfBinary), 0, 12)
        );

        $existingResults = $laboratoryPurchase->results;

        Storage::put($path, $pdfBinary);

        $laboratoryPurchase->results = $path;
        $laboratoryPurchase->save();

        if ($existingResults && $existingResults !== $path && $overwrite) {
            dispatch(function () use ($existingResults) {
                if (Storage::exists($existingResults)) {
                    Storage::delete($existingResults);
                }
            })->afterResponse();
        }

        Log::info('GDA results PDF stored to storage', [
            'purchase_id' => $laboratoryPurchase->id,
            'notification_id' => $metadata['notification_id'] ?? null,
            'path' => $path,
            'source' => $metadata['source'] ?? 'gda',
        ]);

        return $path;
    }
}
