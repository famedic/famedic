<?php

namespace App\Actions;

use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchaseResultsUploaded;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateResultsAction
{
    public function __invoke(
        LaboratoryPurchase $laboratoryPurchase,
        UploadedFile $results
    ): LaboratoryPurchase {
        DB::beginTransaction();

        try {
            $filePath = $results->store('results');

            $existingResults = $laboratoryPurchase->results;

            $laboratoryPurchase->results = $filePath;

            $laboratoryPurchase->save();

            DB::commit();

            if ($existingResults) {
                dispatch(function () use ($existingResults) {
                    if (Storage::exists($existingResults)) {
                        Storage::delete($existingResults);
                    }
                })->afterResponse();
            }

            $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseResultsUploaded($laboratoryPurchase));

            return $laboratoryPurchase;
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
            throw $e;
        }
    }
}
