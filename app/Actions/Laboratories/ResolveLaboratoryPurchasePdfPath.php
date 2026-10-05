<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ResolveLaboratoryPurchasePdfPath
{
    protected ?string $contentHash = null;

    protected string $storageDirectory;

    public function __construct(
        private GenerateLaboratoryPurchaseConfirmationPdf $generatePdf,
    ) {
    }

    /**
     * Devuelve el PDF de la orden en memoria. Usa caché en almacenamiento si está disponible,
     * pero no depende de S3 para generar ni entregar el archivo.
     */
    public function binary(LaboratoryPurchase $laboratoryPurchase): string
    {
        $this->prepare($laboratoryPurchase);

        if ($laboratoryPurchase->pdf_hash === $this->contentHash) {
            $cached = $this->tryGetFromStorage($this->storagePath());

            if ($cached !== null) {
                return $cached;
            }
        }

        if ($gdaBinary = $this->decodeGdaPdfBase64($laboratoryPurchase)) {
            $gdaPath = $this->gdaStoragePath($laboratoryPurchase);
            $this->tryPutToStorage($gdaPath, $gdaBinary);

            return $gdaBinary;
        }

        $this->tryDeleteStaleFile($laboratoryPurchase);

        $binary = $this->generateBinary($laboratoryPurchase);
        $this->tryPutToStorage($this->storagePath(), $binary);
        $laboratoryPurchase->updateQuietly(['pdf_hash' => $this->contentHash]);

        return $binary;
    }

    /**
     * @deprecated Prefer {@see binary()}. Conservado para compatibilidad con flujos que esperan ruta en disco.
     */
    public function __invoke(LaboratoryPurchase $laboratoryPurchase): string
    {
        $this->binary($laboratoryPurchase);

        $gdaPath = $this->gdaStoragePath($laboratoryPurchase);

        if ($this->tryStorageExists($gdaPath)) {
            return $gdaPath;
        }

        return $this->storagePath();
    }

    public function content(LaboratoryPurchase $laboratoryPurchase): string
    {
        return $this->binary($laboratoryPurchase);
    }

    protected function storagePath(): string
    {
        return "{$this->storageDirectory}/{$this->contentHash}.pdf";
    }

    protected function gdaStoragePath(LaboratoryPurchase $laboratoryPurchase): string
    {
        return "{$this->storageDirectory}/gda-order-{$laboratoryPurchase->id}.pdf";
    }

    protected function prepare(LaboratoryPurchase $laboratoryPurchase): void
    {
        $this->storageDirectory = config('famedic.storage_paths.laboratory_purchase_pdfs');

        $laboratoryPurchase->loadMissing([
            'customer.user',
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $laboratoryPurchase->hydrateLaboratoryPurchaseItemsFeatureLists();

        $this->contentHash = $this->calculateContentHash($laboratoryPurchase);
    }

    protected function tryDeleteStaleFile(LaboratoryPurchase $laboratoryPurchase): void
    {
        if (! $laboratoryPurchase->pdf_hash) {
            return;
        }

        $oldPath = "{$this->storageDirectory}/{$laboratoryPurchase->pdf_hash}.pdf";
        $this->tryDeleteFromStorage($oldPath);
    }

    protected function generateBinary(LaboratoryPurchase $laboratoryPurchase): string
    {
        $notifiable = $laboratoryPurchase->customer?->user ?? (object) [
            'name' => 'Cliente',
            'full_name' => null,
        ];

        $withAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($laboratoryPurchase);

        return $this->generatePdf->binary($laboratoryPurchase, $notifiable, $withAppointment);
    }

    /**
     * PDF entregado por GDA al crear la orden (base64 en BD).
     */
    protected function decodeGdaPdfBase64(LaboratoryPurchase $laboratoryPurchase): ?string
    {
        $encoded = $laboratoryPurchase->pdf_base64;

        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        $pdfContent = base64_decode($encoded, true);

        if ($pdfContent === false || $pdfContent === '') {
            return null;
        }

        return $pdfContent;
    }

    protected function tryGetFromStorage(string $path): ?string
    {
        if (! $this->tryStorageExists($path)) {
            return null;
        }

        try {
            return Storage::get($path);
        } catch (Throwable) {
            return null;
        }
    }

    protected function tryPutToStorage(string $path, string $content): void
    {
        try {
            Storage::disk(config('filesystems.default'))->put($path, $content);
        } catch (Throwable) {
            // Caché best-effort: la descarga no debe depender del almacenamiento.
        }
    }

    protected function tryDeleteFromStorage(string $path): void
    {
        try {
            if ($this->tryStorageExists($path)) {
                Storage::delete($path);
            }
        } catch (Throwable) {
        }
    }

    protected function tryStorageExists(string $path): bool
    {
        return storage_path_exists($path);
    }

    protected function calculateContentHash(LaboratoryPurchase $laboratoryPurchase): string
    {
        $contentData = [
            'pdf_engine' => 'dompdf-v7-support-phone-whatsapp',
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
                'total_cents' => $laboratoryPurchase->total_cents,
                'created_at' => $laboratoryPurchase->created_at?->format('Y-m-d H:i:s'),
            ],
            'items' => $laboratoryPurchase->laboratoryPurchaseItems->map(fn ($item) => [
                'name' => $item->name,
                'indications' => $item->indications,
                'feature_list' => LaboratoryPurchaseConfirmationViewData::normalizePackageFeatureList($item->feature_list),
            ])->toArray(),
            'appointment' => $laboratoryPurchase->laboratoryAppointment ? [
                'appointment_date' => $laboratoryPurchase->laboratoryAppointment->appointment_date?->format('Y-m-d H:i:s'),
                'store_name' => $laboratoryPurchase->laboratoryAppointment->laboratoryStore?->name,
            ] : null,
            'transactions' => $laboratoryPurchase->transactions->map(fn ($transaction) => [
                'payment_method' => $transaction->payment_method,
                'payment_status' => $transaction->payment_status,
            ])->toArray(),
        ];

        return substr(md5(serialize($contentData)), 0, 12);
    }
}
