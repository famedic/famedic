<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

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

        $this->deleteStaleFile($laboratoryPurchase);

        $storagePath = $this->storagePath();

        $this->generate($laboratoryPurchase, $storagePath);

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
        Pdf::view('pdfs.laboratory-purchase', [
            'laboratoryPurchase' => $laboratoryPurchase,
            'laboratoryStores' => $this->laboratoryStores,
        ])
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->disk(config('filesystems.default'))
            ->save($storagePath);

        return $storagePath;
    }

    protected function calculateContentHash(LaboratoryPurchase $laboratoryPurchase): string
    {
        $contentData = [
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
