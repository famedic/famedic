<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaResultsNotAvailableException;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Support\Laboratory\GdaResultsPdfAssessment;
use App\Support\Laboratory\GdaResultsPdfStatus;
use DomainException;
use Illuminate\Support\Facades\Log;

class SyncGdaResultPdfToStorageAction
{
    public function __construct(
        protected GetGDAResultsAction $getGdaResultsAction,
        protected StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
        protected ResolveConsultableGdaId $resolveConsultableGdaId,
    ) {
    }

    /**
     * Única operación de sincronización GDA → storage → purchase.results.
     *
     * Usada por:
     * - sincronización inicial (webhook/job, $force = false);
     * - refresh automático de PDF GDA stale ($force = false);
     * - force refresh admin ($force = true).
     *
     * Nunca sobrescribe un PDF manual. Si GDA falla, no toca el PDF anterior.
     *
     * @return string|null Ruta en storage si se guardó o ya existía; null si no aplica.
     *
     * @throws \Throwable Errores transitorios de GDA/storage para permitir reintentos del job.
     */
    public function execute(int $purchaseId, ?int $notificationId = null, bool $force = false): ?string
    {
        $purchase = LaboratoryPurchase::query()->find($purchaseId);

        if (! $purchase) {
            Log::warning('GDA results PDF sync skipped: purchase not found', [
                'purchase_id' => $purchaseId,
                'notification_id' => $notificationId,
                'force' => $force,
            ]);

            return null;
        }

        $purchase->refresh();
        $assessment = GdaResultsPdfStatus::assessPurchase($purchase);
        $oldPath = $purchase->results;

        if ($assessment->isManual()) {
            Log::info('GDA results sync skipped: manual PDF protected', $this->syncLogContext(
                $purchase,
                $notificationId,
                $assessment,
                $oldPath,
                force: $force,
            ));

            return $purchase->results;
        }

        if (! $force && $assessment->isGdaCurrent()) {
            Log::info('GDA results sync skipped: PDF current', $this->syncLogContext(
                $purchase,
                $notificationId,
                $assessment,
                $oldPath,
                force: $force,
            ));

            return $purchase->results;
        }

        if (! $force && ! $assessment->shouldAutomaticallySync()) {
            Log::info('GDA results PDF sync skipped: not a sync candidate', $this->syncLogContext(
                $purchase,
                $notificationId,
                $assessment,
                $oldPath,
                force: $force,
            ));

            return $purchase->results;
        }

        $notification = $this->resolveNotification($purchase, $notificationId);

        if (! $notification || ! $notification->hasAvailableResults()) {
            Log::warning('GDA results PDF sync skipped: results notification unavailable', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notificationId,
                'force' => $force,
                'freshness_status' => $assessment->freshnessStatus,
                'old_path' => $oldPath,
            ]);

            return $oldPath;
        }

        $overwrite = $force
            ? $assessment->isGdaManaged
            : $assessment->isAutomaticOverwriteCandidate;

        if ($overwrite) {
            Log::info($force ? 'GDA results force refresh started' : 'GDA results stale PDF detected', $this->syncLogContext(
                $purchase,
                $notification->id,
                $assessment,
                $oldPath,
                force: $force,
            ));
        }

        try {
            $pdfBase64 = $this->fetchPdfBase64($notification);
        } catch (GdaResultsNotAvailableException $e) {
            Log::warning('GDA results refresh failed, previous PDF preserved', $this->syncLogContext(
                $purchase,
                $notification->id,
                $assessment,
                $oldPath,
                force: $force,
            ) + [
                'order_id' => $e->orderId,
                'gda_message' => $e->gdaMessage,
                'reason' => 'gda_not_available',
            ]);

            throw $e;
        } catch (\Throwable $e) {
            Log::warning('GDA results refresh failed, previous PDF preserved', $this->syncLogContext(
                $purchase,
                $notification->id,
                $assessment,
                $oldPath,
                force: $force,
            ) + [
                'error' => $e->getMessage(),
                'reason' => 'gda_error',
            ]);

            throw $e;
        }

        try {
            $path = $this->storeGdaResultsPdfToStorageAction->execute(
                $purchase,
                $pdfBase64,
                $notification,
                overwrite: $overwrite
            );
        } catch (DomainException $e) {
            Log::error('GDA results PDF sync failed: invalid PDF payload', $this->syncLogContext(
                $purchase,
                $notification->id,
                $assessment,
                $oldPath,
                force: $force,
            ) + [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $purchase->refresh();

        Log::info(
            $overwrite ? 'GDA results refresh completed' : 'GDA results PDF sync completed',
            $this->syncLogContext(
                $purchase,
                $notification->id,
                $assessment,
                $oldPath,
                force: $force,
            ) + [
                'new_path' => $path,
                'path_unchanged' => $oldPath === $path,
            ]
        );

        return $path;
    }

    /**
     * Consulta GDA y devuelve el PDF en Base64. No escribe storage ni purchase.results.
     */
    public function fetchPdfBase64(LaboratoryNotification $notification): string
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

    /**
     * Prefiere el folio consultable de la compra (etiqueta GZ0L…) cuando la
     * notificación guardó un ServiceRequest.id numérico por error histórico.
     */
    private function resolveConsultOrderId(LaboratoryNotification $notification, array $payload): ?string
    {
        $purchase = $this->resolvePurchase($notification);

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

            if ($this->resolveConsultableGdaId->isConsultable($normalized)) {
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

    /**
     * @return array<string, mixed>
     */
    private function syncLogContext(
        LaboratoryPurchase $purchase,
        ?int $notificationId,
        GdaResultsPdfAssessment $assessment,
        ?string $oldPath,
        bool $force,
    ): array {
        return [
            'purchase_id' => $purchase->id,
            'notification_id' => $notificationId,
            'force' => $force,
            'freshness_status' => $assessment->freshnessStatus,
            'pdf_kind' => $assessment->pdfKind,
            'old_path' => $oldPath,
            'is_automatic_overwrite_candidate' => $assessment->isAutomaticOverwriteCandidate,
        ];
    }
}
