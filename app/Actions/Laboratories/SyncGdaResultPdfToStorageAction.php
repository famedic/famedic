<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncGdaResultPdfToStorageAction
{
    public function __construct(
        protected GetGDAResultsAction $getGdaResultsAction,
        protected StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
    ) {
    }

    /**
     * Sincroniza el PDF de resultados GDA hacia storage para una compra.
     *
     * @return string|null Ruta en storage si se guardó o ya existía; null si no aplica.
     *
     * @throws \Throwable Errores transitorios de GDA/storage para permitir reintentos del job.
     */
    public function execute(int $purchaseId, ?int $notificationId = null): ?string
    {
        $purchase = LaboratoryPurchase::query()->find($purchaseId);

        if (! $purchase) {
            Log::warning('GDA results PDF sync skipped: purchase not found', [
                'purchase_id' => $purchaseId,
                'notification_id' => $notificationId,
            ]);

            return null;
        }

        if (! empty($purchase->results) && Storage::exists($purchase->results)) {
            Log::info('GDA results PDF already exists, skipping', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notificationId,
                'existing_results' => $purchase->results,
            ]);

            return $purchase->results;
        }

        $notification = $this->resolveNotification($purchase, $notificationId);

        if (! $notification || ! $notification->hasAvailableResults()) {
            Log::warning('GDA results PDF sync skipped: results notification unavailable', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notificationId,
            ]);

            return null;
        }

        $orderId = $notification->gda_order_id ?? $purchase->gda_order_id;

        if (! $orderId) {
            Log::warning('GDA results PDF sync skipped: missing GDA order id', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification->id,
            ]);

            return null;
        }

        $payload = $this->resolvePayload($notification);
        $results = ($this->getGdaResultsAction)($orderId, $payload);
        $pdfBase64 = $results['infogda_resultado_b64'] ?? null;

        if (empty($pdfBase64)) {
            throw new \RuntimeException('No se encontraron resultados PDF en la respuesta de GDA.');
        }

        try {
            $path = $this->storeGdaResultsPdfToStorageAction->execute(
                $purchase,
                $pdfBase64,
                $notification,
                overwrite: false
            );
        } catch (DomainException $e) {
            Log::error('GDA results PDF sync failed: invalid PDF payload', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('GDA results PDF sync completed', [
            'purchase_id' => $purchase->id,
            'notification_id' => $notification->id,
            'path' => $path,
        ]);

        return $path;
    }

    private function resolveNotification(LaboratoryPurchase $purchase, ?int $notificationId): ?LaboratoryNotification
    {
        if ($notificationId) {
            $notification = LaboratoryNotification::query()->find($notificationId);

            if ($notification) {
                return LaboratoryNotification::latestResultsForOrder(
                    $purchase->id,
                    $notification->gda_order_id ?? $purchase->gda_order_id,
                    $notification->gda_consecutivo ?? $purchase->gda_consecutivo
                ) ?? $notification;
            }
        }

        return LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );
    }

    private function resolvePayload(LaboratoryNotification $notification): array
    {
        $payload = $notification->payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            throw new \RuntimeException('No se pudo obtener el payload de la notificación.');
        }

        return $payload;
    }
}
