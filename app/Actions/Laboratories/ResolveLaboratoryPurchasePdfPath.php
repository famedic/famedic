<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use App\Support\BrowsershotConfigurator;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ResolveLaboratoryPurchasePdfPath
{
    protected ?string $contentHash = null;

    protected string $storageDirectory;

    protected Collection $laboratoryStores;

    public function __invoke(LaboratoryPurchase $laboratoryPurchase): string
    {
        $this->prepare($laboratoryPurchase);

        if ($laboratoryPurchase->pdf_hash === $this->contentHash) {
            $storagePath = $this->storagePath();

            if (Storage::exists($storagePath)) {
                return $storagePath;
            }
        }

        if ($gdaPath = $this->resolveFromGdaPdfBase64($laboratoryPurchase)) {
            return $gdaPath;
        }

        $this->deleteStaleFile($laboratoryPurchase);

        $storagePath = $this->storagePath();

        try {
            $this->generate($laboratoryPurchase, $storagePath);
        } catch (ProcessFailedException $e) {
            if ($gdaPath = $this->resolveFromGdaPdfBase64($laboratoryPurchase)) {
                return $gdaPath;
            }

            throw $e;
        }

        $laboratoryPurchase->updateQuietly(['pdf_hash' => $this->contentHash]);

        return $storagePath;
    }

    public function content(LaboratoryPurchase $laboratoryPurchase): string
    {
        return Storage::get($this($laboratoryPurchase));
    }

    protected function storagePath(): string
    {
        return "{$this->storageDirectory}/{$this->contentHash}.pdf";
    }

    protected function prepare(LaboratoryPurchase $laboratoryPurchase): void
    {
        $this->storageDirectory = config('famedic.storage_paths.laboratory_purchase_pdfs');
        $this->laboratoryStores = LaboratoryStore::ofBrand($laboratoryPurchase->brand)->get();

        $laboratoryPurchase->loadMissing([
            'customer.user',
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $this->contentHash = $this->calculateContentHash($laboratoryPurchase);
    }

    protected function deleteStaleFile(LaboratoryPurchase $laboratoryPurchase): void
    {
        if ($laboratoryPurchase->pdf_hash) {
            $oldPath = "{$this->storageDirectory}/{$laboratoryPurchase->pdf_hash}.pdf";

            if (Storage::exists($oldPath)) {
                Storage::delete($oldPath);
            }
        }
    }

    protected function generate(LaboratoryPurchase $laboratoryPurchase, string $storagePath): string
    {
        $notifiable = $laboratoryPurchase->customer?->user ?? (object) [
            'name' => 'Cliente',
            'full_name' => null,
        ];

        $confirmation = LaboratoryPurchaseConfirmationViewData::build($laboratoryPurchase, $notifiable, true);
        $withAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($laboratoryPurchase);

        Pdf::view('pdfs.laboratory-purchase-confirmation', array_merge($confirmation, [
            'withAppointment' => $withAppointment,
        ]))
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->withBrowsershot(fn ($browsershot) => BrowsershotConfigurator::apply($browsershot))
            ->disk(config('filesystems.default'))
            ->save($storagePath);

        return $storagePath;
    }

    /**
     * PDF entregado por GDA al crear la orden (base64 en BD).
     */
    protected function resolveFromGdaPdfBase64(LaboratoryPurchase $laboratoryPurchase): ?string
    {
        $encoded = $laboratoryPurchase->pdf_base64;

        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        $pdfContent = base64_decode($encoded, true);

        if ($pdfContent === false || $pdfContent === '') {
            return null;
        }

        $storagePath = "{$this->storageDirectory}/gda-order-{$laboratoryPurchase->id}.pdf";

        if (! Storage::exists($storagePath)) {
            Storage::put($storagePath, $pdfContent);
        }

        return $storagePath;
    }

    protected function calculateContentHash(LaboratoryPurchase $laboratoryPurchase): string
    {
        $contentData = [
            'pdf_template' => 'laboratory-confirmation-email-v1',
            'purchase' => [
                'id' => $laboratoryPurchase->id,
                'gda_order_id' => $laboratoryPurchase->gda_order_id,
                'brand' => $laboratoryPurchase->brand->value,
                'name' => $laboratoryPurchase->name,
                'paternal_lastname' => $laboratoryPurchase->paternal_lastname,
                'maternal_lastname' => $laboratoryPurchase->maternal_lastname,
                'phone' => $laboratoryPurchase->phone,
                'birth_date' => $laboratoryPurchase->birth_date?->format('Y-m-d'),
                'gender' => $laboratoryPurchase->gender?->value,
                'street' => $laboratoryPurchase->street,
                'number' => $laboratoryPurchase->number,
                'neighborhood' => $laboratoryPurchase->neighborhood,
                'city' => $laboratoryPurchase->city,
                'state' => $laboratoryPurchase->state,
                'zipcode' => $laboratoryPurchase->zipcode,
                'additional_references' => $laboratoryPurchase->additional_references,
                'total_cents' => $laboratoryPurchase->total_cents,
                'created_at' => $laboratoryPurchase->created_at?->format('Y-m-d H:i:s'),
            ],
            'items' => $laboratoryPurchase->laboratoryPurchaseItems->map(fn ($item) => [
                'name' => $item->name,
                'indications' => $item->indications,
                'price_cents' => $item->price_cents,
            ])->toArray(),
            'appointment' => $laboratoryPurchase->laboratoryAppointment ? [
                'appointment_date' => $laboratoryPurchase->laboratoryAppointment->appointment_date?->format('Y-m-d H:i:s'),
                'notes' => $laboratoryPurchase->laboratoryAppointment->notes,
                'store_name' => $laboratoryPurchase->laboratoryAppointment->laboratoryStore?->name,
                'store_address' => $laboratoryPurchase->laboratoryAppointment->laboratoryStore?->address,
            ] : null,
            'transactions' => $laboratoryPurchase->transactions->map(fn ($transaction) => [
                'payment_method' => $transaction->payment_method,
                'details' => $transaction->details,
            ])->toArray(),
            'laboratory_stores' => $this->laboratoryStores->map(fn ($store) => [
                'name' => $store->name,
                'address' => $store->address,
                'state' => $store->state,
            ])->toArray(),
        ];

        return substr(md5(serialize($contentData)), 0, 12);
    }
}
