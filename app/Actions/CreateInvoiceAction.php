<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Notifications\PurchaseInvoiceUploaded;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditHint;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateInvoiceAction
{
    public function __construct(
        private readonly BillingInvoiceDocumentsAuditRecorder $billingInvoiceDocumentsAuditRecorder,
    ) {}

    public function __invoke(
        Model $model,
        ?UploadedFile $invoice = null,
        ?UploadedFile $invoiceXml = null,
        ?BillingInvoiceDocumentsAuditHint $auditHint = null,
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
            $wasAlreadyComplete = $existingInvoice !== null && filled($existingInvoice->completed_at);
            $becameComplete = false;
            $pdfReplaced = false;
            $xmlReplaced = false;

            if (! $existingInvoice) {
                $pdfPath = $newPdfPath;
                $xmlPath = $newXmlPath;
                $attributes = [
                    'invoice' => $pdfPath,
                    'invoice_xml' => $xmlPath,
                ];

                if ($this->isCompletePaths($pdfPath, $xmlPath)) {
                    $attributes['completed_at'] = now();
                    $becameComplete = true;
                }

                $newInvoice = $model->invoice()->create($attributes);
            } else {
                $updates = [];

                if ($newPdfPath) {
                    $previousPdfPath = $existingInvoice->invoice;
                    $updates['invoice'] = $newPdfPath;
                    $pdfReplaced = true;
                }

                if ($newXmlPath) {
                    $previousXmlPath = $existingInvoice->invoice_xml;
                    $updates['invoice_xml'] = $newXmlPath;
                    $xmlReplaced = true;
                }

                $resultingPdf = array_key_exists('invoice', $updates)
                    ? $updates['invoice']
                    : $existingInvoice->getRawOriginal('invoice');
                $resultingXml = array_key_exists('invoice_xml', $updates)
                    ? $updates['invoice_xml']
                    : $existingInvoice->getRawOriginal('invoice_xml');

                if (
                    blank($existingInvoice->completed_at)
                    && $this->isCompletePaths($resultingPdf, $resultingXml)
                ) {
                    $updates['completed_at'] = now();
                    $becameComplete = true;
                }

                // Conserva created_at original; updated_at lo gestiona Eloquent.
                if ($updates !== []) {
                    $existingInvoice->update($updates);
                }

                $newInvoice = $existingInvoice->fresh();
            }

            DB::commit();

            // Post-commit only: rollback never reaches here.
            if ($auditHint !== null) {
                if ($becameComplete) {
                    $this->billingInvoiceDocumentsAuditRecorder->recordCompleted(
                        (int) $newInvoice->id,
                        $auditHint,
                    );
                } elseif ($wasAlreadyComplete && ($pdfReplaced || $xmlReplaced)) {
                    $this->billingInvoiceDocumentsAuditRecorder->recordDocumentsReplaced(
                        (int) $newInvoice->id,
                        $auditHint,
                        $pdfReplaced,
                        $xmlReplaced,
                    );
                }
            }

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

    private function isCompletePaths(?string $pdfPath, ?string $xmlPath): bool
    {
        return filled($pdfPath) && filled($xmlPath);
    }
}
