<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $laboratoryPurchase->id,
            substr(hash('sha256', $pdfBinary), 0, 12)
        );

        $existingResults = $laboratoryPurchase->results;

        if ($existingResults === $path && Storage::exists($path)) {
            Log::info('GDA results PDF unchanged, reusing existing path', [
                'purchase_id' => $laboratoryPurchase->id,
                'notification_id' => $metadata['notification_id'] ?? null,
                'path' => $path,
            ]);

            return $path;
        }

        $stored = Storage::put($path, $pdfBinary);

        if (! $stored || ! Storage::exists($path)) {
            Log::error('GDA results PDF storage write verification failed', [
                'purchase_id' => $laboratoryPurchase->id,
                'notification_id' => $metadata['notification_id'] ?? null,
                'path' => $path,
                'disk' => config('filesystems.default'),
                'source' => $metadata['source'] ?? 'gda',
            ]);

            throw new RuntimeException('No se pudo confirmar el archivo PDF en storage.');
        }

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
