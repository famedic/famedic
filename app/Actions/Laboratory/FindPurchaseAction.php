<?php
// app/Actions/Laboratory/FindPurchaseAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryPurchase;
use App\Support\GDA\GdaWebhookPayloadResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindPurchaseAction
{
    public function __construct(
        protected GdaWebhookPayloadResolver $payloadResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $resolved  Resultado de GdaWebhookPayloadResolver::resolve()
     */
    public function execute(array $resolved, ?string $gdaAcuse = null): ?LaboratoryPurchase
    {
        $columns = Schema::getColumnListing('laboratory_purchases');
        $acuse = $gdaAcuse ?? $resolved['acuse'] ?? null;

        if (! empty($resolved['gda_order_id']) && in_array('gda_order_id', $columns)) {
            $purchase = LaboratoryPurchase::where('gda_order_id', $resolved['gda_order_id'])->first();
            if ($purchase) {
                Log::info('Found purchase by gda_order_id', [
                    'gda_order_id' => $resolved['gda_order_id'],
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        $etiqueta = $resolved['infogda_etiqueta'] ?? null;
        $gdaOrderId = $resolved['gda_order_id'] ?? null;
        if (
            $etiqueta
            && $etiqueta !== $gdaOrderId
            && in_array('gda_order_id', $columns)
        ) {
            $purchase = LaboratoryPurchase::where('gda_order_id', $etiqueta)->first();
            if ($purchase) {
                Log::info('Found purchase by infogda_etiqueta', [
                    'infogda_etiqueta' => $etiqueta,
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        if (
            isset($resolved['gda_consecutivo'])
            && $resolved['gda_consecutivo'] !== null
            && in_array('gda_consecutivo', $columns)
        ) {
            $purchase = LaboratoryPurchase::where('gda_consecutivo', $resolved['gda_consecutivo'])->first();
            if ($purchase) {
                Log::info('Found purchase by gda_consecutivo', [
                    'gda_consecutivo' => $resolved['gda_consecutivo'],
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        $serviceRequestId = $resolved['service_request_id'] ?? null;
        if (
            $serviceRequestId
            && $this->payloadResolver->isNumericConsecutivo($serviceRequestId)
            && in_array('gda_consecutivo', $columns)
        ) {
            $purchase = LaboratoryPurchase::where('gda_consecutivo', (int) $serviceRequestId)->first();
            if ($purchase) {
                Log::info('Found purchase by gda_consecutivo', [
                    'gda_consecutivo' => (int) $serviceRequestId,
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        if ($acuse && in_array('gda_acuse', $columns)) {
            $purchase = LaboratoryPurchase::where('gda_acuse', $acuse)->first();
            if ($purchase) {
                Log::info('Found purchase by gda_acuse', [
                    'gda_acuse' => $acuse,
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        $requisitionValue = $resolved['requisition_value'] ?? null;
        if ($requisitionValue && $this->payloadResolver->isNumericConsecutivo($requisitionValue)) {
            $purchase = LaboratoryPurchase::find((int) $requisitionValue);
            if ($purchase) {
                Log::info('Found purchase by requisition.value', [
                    'requisition_value' => $requisitionValue,
                    'purchase_id' => $purchase->id,
                ]);

                return $purchase;
            }
        }

        $purchase = $this->findByAlternateFields($resolved, $columns);
        if ($purchase) {
            Log::info('Found purchase by other fields', [
                'purchase_id' => $purchase->id,
            ]);

            return $purchase;
        }

        Log::info('No purchase found', [
            'gda_order_id' => $resolved['gda_order_id'] ?? null,
            'gda_consecutivo' => $resolved['gda_consecutivo'] ?? null,
            'requisition_value' => $requisitionValue,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  list<string>  $columns
     */
    protected function findByAlternateFields(array $resolved, array $columns): ?LaboratoryPurchase
    {
        $gdaOrderId = $resolved['gda_order_id'] ?? null;
        $requisitionValue = $resolved['requisition_value'] ?? null;

        if (! $gdaOrderId && ! $requisitionValue) {
            return null;
        }

        return LaboratoryPurchase::where(function ($query) use ($gdaOrderId, $requisitionValue, $columns) {
            if ($gdaOrderId && $this->payloadResolver->isNumericConsecutivo($gdaOrderId)) {
                $query->where('id', (int) $gdaOrderId);
            }

            if (in_array('order_reference', $columns)) {
                if ($gdaOrderId) {
                    $query->orWhere('order_reference', $gdaOrderId);
                }
                if ($requisitionValue) {
                    $query->orWhere('order_reference', $requisitionValue);
                }
            }

            if (in_array('reference', $columns)) {
                if ($gdaOrderId) {
                    $query->orWhere('reference', $gdaOrderId);
                }
                if ($requisitionValue) {
                    $query->orWhere('reference', $requisitionValue);
                }
            }
        })->first();
    }
}
