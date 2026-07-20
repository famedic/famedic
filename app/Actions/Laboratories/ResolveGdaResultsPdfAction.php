<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaResultsNotAvailableException;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResolveGdaResultsPdfAction
{
    public function __construct(
        protected GetGDAResultsAction $getGdaResultsAction,
        protected StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
    ) {
    }

    /**
     * Resuelve el PDF de resultados para una notificación, usando siempre la notificación
     * más reciente de la orden y refrescando desde GDA cuando hay eventos nuevos.
     *
     * @return array{pdf_base64: string, storage_path: string|null, notification: LaboratoryNotification, cached: bool, refreshed: bool}
     */
    public function __invoke(LaboratoryNotification $notification): array
    {
        $notification = $this->resolveTargetNotification($notification);

        if (! $notification->hasAvailableResults()) {
            throw new \RuntimeException('Los resultados aún no están disponibles.');
        }

        $purchase = $this->resolvePurchase($notification);

        if ($purchase && $this->hasStoredResults($purchase)) {
            Log::info('Using stored GDA results PDF from purchase.results', [
                'notification_id' => $notification->id,
                'purchase_id' => $purchase->id,
                'path' => $purchase->results,
            ]);

            return $this->buildResultFromStorage($purchase, $notification, cached: true, refreshed: false);
        }

        if (! empty($notification->results_pdf_base64) && $purchase) {
            try {
                $this->storeGdaResultsPdfToStorageAction->execute(
                    $purchase,
                    $notification->results_pdf_base64,
                    $notification,
                    overwrite: false
                );

                $purchase = $purchase->fresh();

                if ($this->hasStoredResults($purchase)) {
                    return $this->buildResultFromStorage(
                        $purchase,
                        $notification->fresh(),
                        cached: true,
                        refreshed: false
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to migrate legacy GDA results PDF base64 to storage', [
                    'notification_id' => $notification->id,
                    'purchase_id' => $purchase->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($notification->results_pdf_base64) && ! $notification->shouldRefreshPdfFromGda()) {
            Log::info('Using legacy cached GDA results PDF base64', [
                'notification_id' => $notification->id,
                'gda_order_id' => $notification->gda_order_id,
            ]);

            return [
                'pdf_base64' => $notification->results_pdf_base64,
                'storage_path' => null,
                'notification' => $notification,
                'cached' => true,
                'refreshed' => false,
            ];
        }

        Log::info('Refreshing GDA results PDF from provider API', [
            'notification_id' => $notification->id,
            'gda_order_id' => $notification->gda_order_id,
            'latest_results_at' => static::latestResultsReceivedAtForOrder(
                $notification->laboratory_purchase_id,
                $notification->gda_order_id,
                $notification->gda_consecutivo
            )?->toIso8601String(),
            'pdf_fetched_at' => $notification->pdfFetchedAt()?->toIso8601String(),
        ]);

        $pdfBase64 = $this->fetchFromGdaApi($notification);

        if ($purchase) {
            $this->storeGdaResultsPdfToStorageAction->execute(
                $purchase,
                $pdfBase64,
                $notification,
                overwrite: false
            );

            return $this->buildResultFromStorage(
                $purchase->fresh(),
                $notification->fresh(),
                cached: false,
                refreshed: true
            );
        }

        $notification->update([
            'gda_message' => array_merge($notification->gda_message ?? [], [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'gda_api',
            ]),
        ]);

        return [
            'pdf_base64' => $pdfBase64,
            'storage_path' => null,
            'notification' => $notification->fresh(),
            'cached' => false,
            'refreshed' => true,
        ];
    }

    /**
     * Fuerza la consulta a GDA y guarda el resultado en storage cuando hay compra asociada.
     *
     * @return array{pdf_base64: string, storage_path: string|null, notification: LaboratoryNotification, cached: bool, refreshed: bool, forced: bool}
     */
    public function forceRefresh(LaboratoryNotification $notification): array
    {
        $notification = $this->resolveTargetNotification($notification);

        if (! $notification->hasAvailableResults()) {
            throw new \RuntimeException('Los resultados aún no están disponibles.');
        }

        $purchase = $this->resolvePurchase($notification);

        if ($purchase && $this->hasStoredResults($purchase) && ! $this->isGdaManagedResultsPath($purchase->results)) {
            Log::warning('GDA results PDF not stored because purchase already has results', [
                'purchase_id' => $purchase->id,
                'existing_results' => $purchase->results,
            ]);

            return array_merge(
                $this->buildResultFromStorage($purchase, $notification, cached: true, refreshed: false),
                ['forced' => false]
            );
        }

        LaboratoryNotification::query()
            ->ofResultsType()
            ->forSameOrderAs($notification)
            ->whereNotNull('results_pdf_base64')
            ->update(['results_pdf_base64' => null]);

        $notification->refresh();

        Log::info('Admin forced GDA results PDF refresh', [
            'notification_id' => $notification->id,
            'gda_order_id' => $notification->gda_order_id,
        ]);

        $pdfBase64 = $this->fetchFromGdaApi($notification);

        if ($purchase) {
            $this->storeGdaResultsPdfToStorageAction->execute(
                $purchase,
                $pdfBase64,
                $notification,
                overwrite: true
            );

            return array_merge(
                $this->buildResultFromStorage($purchase->fresh(), $notification->fresh(), cached: false, refreshed: true),
                ['forced' => true]
            );
        }

        $notification->update([
            'gda_message' => array_merge($notification->gda_message ?? [], [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'gda_api',
                'admin_forced_refresh_at' => now()->toISOString(),
            ]),
        ]);

        return [
            'pdf_base64' => $pdfBase64,
            'storage_path' => null,
            'notification' => $notification->fresh(),
            'cached' => false,
            'refreshed' => true,
            'forced' => true,
        ];
    }

    public function resolveTargetNotification(LaboratoryNotification $notification): LaboratoryNotification
    {
        return LaboratoryNotification::latestResultsForOrder(
            $notification->laboratory_purchase_id,
            $notification->gda_order_id,
            $notification->gda_consecutivo
        ) ?? $notification;
    }

    private function fetchFromGdaApi(LaboratoryNotification $notification): string
    {
        $payload = $this->resolvePayload($notification);
        $orderId = $this->resolveConsultOrderId($notification, $payload);

        if (! $orderId) {
            throw new \RuntimeException('Falta el ID de orden GDA.');
        }

        $marca = $payload['header']['marca'] ?? null;
        $convenio = $payload['requisition']['convenio'] ?? null;

        if (! $marca || ! $convenio) {
            throw new \RuntimeException('No se encontraron marca o convenio en el payload de la notificación.');
        }

        try {
            $results = ($this->getGdaResultsAction)($orderId, $payload);
        } catch (GdaResultsNotAvailableException $e) {
            $notification->update([
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'last_gda_not_available_at' => now()->toISOString(),
                    'last_gda_not_available_message' => $e->gdaMessage,
                    'last_gda_not_available_consult_id' => $orderId,
                ]),
            ]);

            throw $e;
        }

        $pdfBase64 = $results['infogda_resultado_b64'] ?? null;

        if (empty($pdfBase64)) {
            throw new \RuntimeException('No se encontraron resultados PDF en la respuesta de GDA.');
        }

        $notification->update([
            'gda_message' => array_merge($notification->gda_message ?? [], [
                'last_gda_not_available_at' => null,
                'last_gda_not_available_message' => null,
                'last_successful_consult_id' => $orderId,
            ]),
        ]);

        return $pdfBase64;
    }

    /**
     * Prefiere el folio consultable de la compra (etiqueta GZ0L…) cuando la
     * notificación guardó un ServiceRequest.id numérico por error histórico.
     */
    private function resolveConsultOrderId(LaboratoryNotification $notification, array $payload): ?string
    {
        $purchase = $this->resolvePurchase($notification);
        $resolver = app(ResolveConsultableGdaId::class);

        $candidates = [
            $purchase?->gda_order_id,
            data_get($payload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta'),
            $notification->gda_order_id,
            data_get($payload, 'id'),
            data_get($payload, 'requisition.value'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $normalized = (string) $candidate;

            if ($resolver->isConsultable($normalized)) {
                return $normalized;
            }
        }

        return $notification->gda_order_id
            ?: (data_get($payload, 'id') ? (string) data_get($payload, 'id') : null);
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

    private function resolvePurchase(LaboratoryNotification $notification): ?LaboratoryPurchase
    {
        if (! $notification->laboratory_purchase_id) {
            return null;
        }

        return $notification->relationLoaded('laboratoryPurchase')
            ? $notification->laboratoryPurchase
            : LaboratoryPurchase::query()->find($notification->laboratory_purchase_id);
    }

    private function hasStoredResults(LaboratoryPurchase $purchase): bool
    {
        return ! empty($purchase->results) && Storage::exists($purchase->results);
    }

    private function isGdaManagedResultsPath(string $path): bool
    {
        return str_contains($path, 'results/gda-');
    }

    /**
     * @return array{pdf_base64: string, storage_path: string, notification: LaboratoryNotification, cached: bool, refreshed: bool}
     */
    private function buildResultFromStorage(
        LaboratoryPurchase $purchase,
        LaboratoryNotification $notification,
        bool $cached,
        bool $refreshed
    ): array {
        $binary = Storage::get($purchase->results);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('No se pudo leer el PDF de resultados almacenado.');
        }

        return [
            'pdf_base64' => base64_encode($binary),
            'storage_path' => $purchase->results,
            'notification' => $notification,
            'cached' => $cached,
            'refreshed' => $refreshed,
        ];
    }

    private static function latestResultsReceivedAtForOrder(
        ?int $purchaseId,
        ?string $gdaOrderId,
        ?string $gdaConsecutivo
    ) {
        return LaboratoryNotification::latestResultsReceivedAtForOrder(
            $purchaseId,
            $gdaOrderId,
            $gdaConsecutivo
        );
    }
}
