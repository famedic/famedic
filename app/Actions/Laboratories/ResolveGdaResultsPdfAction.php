<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResolveGdaResultsPdfAction
{
    public function __construct(
        protected StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
        protected SyncGdaResultPdfToStorageAction $syncGdaResultPdfToStorageAction,
        protected EnsureLatestGdaResultsPdfAction $ensureLatestGdaResultsPdfAction,
    ) {
    }

    /**
     * Resuelve el PDF de resultados para una notificación, usando siempre la notificación
     * más reciente de la orden y refrescando desde GDA cuando hay eventos nuevos.
     *
     * @return array{pdf_base64: string, storage_path: string|null, notification: LaboratoryNotification, cached: bool, refreshed: bool, refresh_dispatched: bool}
     */
    public function __invoke(LaboratoryNotification $notification): array
    {
        $notification = $this->resolveTargetNotification($notification);

        if (! $notification->hasAvailableResults()) {
            throw new \RuntimeException('Los resultados aún no están disponibles.');
        }

        $purchase = $this->resolvePurchase($notification);

        if ($purchase && $this->hasStoredResults($purchase)) {
            $ensure = $this->ensureLatestGdaResultsPdfAction->execute($purchase, 'resolve');

            Log::info('Using stored GDA results PDF from purchase.results', [
                'notification_id' => $notification->id,
                'purchase_id' => $purchase->id,
                'path' => $purchase->results,
                'freshness_status' => $ensure['assessment']?->freshnessStatus,
                'refresh_dispatched' => $ensure['refresh_dispatched'],
            ]);

            return $this->buildResultFromStorage(
                $purchase,
                $notification,
                cached: true,
                refreshed: false,
                refreshDispatched: $ensure['refresh_dispatched'],
            );
        }

        if ($purchase) {
            $this->ensureLatestGdaResultsPdfAction->execute($purchase, 'resolve_no_stored_pdf');
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
                'refresh_dispatched' => false,
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

        if ($purchase) {
            $this->syncGdaResultPdfToStorageAction->execute(
                $purchase->id,
                $notification->id,
                force: false
            );

            return $this->buildResultFromStorage(
                $purchase->fresh(),
                $notification->fresh(),
                cached: false,
                refreshed: true
            );
        }

        $pdfBase64 = $this->fetchFromGdaApi($notification);

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
            'refresh_dispatched' => false,
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

        if ($purchase && $this->hasStoredResults($purchase) && ! GdaResultsPdfStatus::isGdaManagedPath($purchase->results)) {
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
            'purchase_id' => $purchase?->id,
        ]);

        if ($purchase) {
            $this->syncGdaResultPdfToStorageAction->execute(
                $purchase->id,
                $notification->id,
                force: true
            );

            return array_merge(
                $this->buildResultFromStorage($purchase->fresh(), $notification->fresh(), cached: false, refreshed: true),
                ['forced' => true]
            );
        }

        $pdfBase64 = $this->fetchFromGdaApi($notification);

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
        return $this->syncGdaResultPdfToStorageAction->fetchPdfBase64($notification);
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

    /**
     * @return array{pdf_base64: string, storage_path: string, notification: LaboratoryNotification, cached: bool, refreshed: bool, refresh_dispatched: bool}
     */
    private function buildResultFromStorage(
        LaboratoryPurchase $purchase,
        LaboratoryNotification $notification,
        bool $cached,
        bool $refreshed,
        bool $refreshDispatched = false
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
            'refresh_dispatched' => $refreshDispatched,
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
