<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use Illuminate\Support\Facades\Log;

class ResolveGdaResultsPdfAction
{
    public function __construct(
        protected GetGDAResultsAction $getGdaResultsAction
    ) {
    }

    /**
     * Resuelve el PDF de resultados para una notificación, usando siempre la notificación
     * más reciente de la orden y refrescando desde GDA cuando hay eventos nuevos.
     *
     * @return array{pdf_base64: string, notification: LaboratoryNotification, cached: bool, refreshed: bool}
     */
    public function __invoke(LaboratoryNotification $notification): array
    {
        $notification = $this->resolveTargetNotification($notification);

        if (! $notification->hasAvailableResults()) {
            throw new \RuntimeException('Los resultados aún no están disponibles.');
        }

        if (! $notification->shouldRefreshPdfFromGda()) {
            Log::info('Using cached GDA results PDF', [
                'notification_id' => $notification->id,
                'gda_order_id' => $notification->gda_order_id,
            ]);

            return [
                'pdf_base64' => $notification->results_pdf_base64,
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

        $notification->update([
            'results_pdf_base64' => $pdfBase64,
            'gda_message' => array_merge($notification->gda_message ?? [], [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'gda_api',
            ]),
        ]);

        return [
            'pdf_base64' => $pdfBase64,
            'notification' => $notification->fresh(),
            'cached' => false,
            'refreshed' => true,
        ];
    }

    /**
     * Fuerza la consulta a GDA, limpia PDFs cacheados previos y guarda el resultado más reciente.
     *
     * @return array{pdf_base64: string, notification: LaboratoryNotification, cached: bool, refreshed: bool, forced: bool}
     */
    public function forceRefresh(LaboratoryNotification $notification): array
    {
        $notification = $this->resolveTargetNotification($notification);

        if (! $notification->hasAvailableResults()) {
            throw new \RuntimeException('Los resultados aún no están disponibles.');
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

        $notification->update([
            'results_pdf_base64' => $pdfBase64,
            'gda_message' => array_merge($notification->gda_message ?? [], [
                'results_fetched_at' => now()->toISOString(),
                'results_source' => 'gda_api',
                'admin_forced_refresh_at' => now()->toISOString(),
            ]),
        ]);

        return [
            'pdf_base64' => $pdfBase64,
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
        $orderId = $notification->gda_order_id;
        $payload = $this->resolvePayload($notification);

        if (! $orderId) {
            throw new \RuntimeException('Falta el ID de orden GDA.');
        }

        $marca = $payload['header']['marca'] ?? null;
        $convenio = $payload['requisition']['convenio'] ?? null;

        if (! $marca || ! $convenio) {
            throw new \RuntimeException('No se encontraron marca o convenio en el payload de la notificación.');
        }

        $results = ($this->getGdaResultsAction)($orderId, $payload);
        $pdfBase64 = $results['infogda_resultado_b64'] ?? null;

        if (empty($pdfBase64)) {
            throw new \RuntimeException('No se encontraron resultados PDF en la respuesta de GDA.');
        }

        return $pdfBase64;
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
