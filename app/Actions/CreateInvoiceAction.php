<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Notifications\PurchaseInvoiceUploaded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateInvoiceAction
{
    public function __invoke(
        Model $model,
        UploadedFile $invoice
    ): Invoice {
        DB::beginTransaction();

        try {
            $filePath = $invoice->store('invoices');
            $existingInvoice = $model->invoice;

            if (!$existingInvoice) {
                $newInvoice = $model->invoice()->create([
                    'invoice' => $filePath
                ]);
            } else {
                $previousPath = $existingInvoice->invoice;
                $existingInvoice->update([
                    'invoice' => $filePath,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $newInvoice = $existingInvoice;
            }

            DB::commit();

            if ($existingInvoice) {
                dispatch(function () use ($previousPath) {
                    if (Storage::exists($previousPath)) {
                        Storage::delete($previousPath);
                    }
                })->afterResponse();
            }

            if ($model instanceof LaboratoryPurchase || $model instanceof OnlinePharmacyPurchase) {
                $model->customer->user->notify(new PurchaseInvoiceUploaded($model));
            }

            return $newInvoice;
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
            throw $e;
        }
    }
}
