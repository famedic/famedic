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
        ?UploadedFile $invoice = null,
        ?UploadedFile $invoiceXml = null,
    ): Invoice {
        DB::beginTransaction();

        $newPdfPath = null;
        $newXmlPath = null;

        try {
            $existingInvoice = $model->invoice;

            if ($invoice) {
                $newPdfPath = $invoice->store('invoices');
            }

            if ($invoiceXml) {
                $newXmlPath = $invoiceXml->store('invoices');
            }

            $previousPdfPath = null;
            $previousXmlPath = null;

            if (! $existingInvoice) {
                $newInvoice = $model->invoice()->create([
                    'invoice' => $newPdfPath,
                    'invoice_xml' => $newXmlPath,
                ]);
            } else {
                $updates = [
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($newPdfPath) {
                    $previousPdfPath = $existingInvoice->invoice;
                    $updates['invoice'] = $newPdfPath;
                }

                if ($newXmlPath) {
                    $previousXmlPath = $existingInvoice->invoice_xml;
                    $updates['invoice_xml'] = $newXmlPath;
                }

                $existingInvoice->update($updates);
                $newInvoice = $existingInvoice;
            }

            DB::commit();

            if ($previousPdfPath || $previousXmlPath) {
                dispatch(function () use ($previousPdfPath, $previousXmlPath) {
                    if ($previousPdfPath && Storage::exists($previousPdfPath)) {
                        Storage::delete($previousPdfPath);
                    }

                    if ($previousXmlPath && Storage::exists($previousXmlPath)) {
                        Storage::delete($previousXmlPath);
                    }
                })->afterResponse();
            }

            if ($model instanceof LaboratoryPurchase || $model instanceof OnlinePharmacyPurchase) {
                $model->customer->user->notify(new PurchaseInvoiceUploaded($model));
            }

            return $newInvoice;
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($newPdfPath && Storage::exists($newPdfPath)) {
                Storage::delete($newPdfPath);
            }

            if ($newXmlPath && Storage::exists($newXmlPath)) {
                Storage::delete($newXmlPath);
            }

            throw $e;
        }
    }
}
