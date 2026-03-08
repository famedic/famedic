<?php
// app/Actions/Laboratory/FindPurchaseAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindPurchaseAction
{
    public function execute(string $gdaOrderId, ?string $gdaExternalId, ?string $gdaAcuse): ?LaboratoryPurchase
    {
        $columns = Schema::getColumnListing('laboratory_purchases');
        
        // Búsqueda por campos exactos
        $exactStrategies = [
            'gda_consecutivo' => $gdaOrderId,
            'gda_acuse' => $gdaAcuse,
            'gda_order_id' => $gdaOrderId,
        ];

        foreach ($exactStrategies as $field => $value) {
            if ($value && in_array($field, $columns)) {
                $purchase = LaboratoryPurchase::where($field, $value)->first();
                if ($purchase) {
                    Log::info('Found purchase by ' . $field, [
                        $field => $value,
                        'purchase_id' => $purchase->id
                    ]);
                    return $purchase;
                }
            }
        }

        // Búsqueda por campos alternativos
        $purchase = LaboratoryPurchase::where(function ($query) use ($gdaOrderId, $gdaExternalId, $columns) {
            $query->where('id', $gdaOrderId);
            
            if (in_array('order_reference', $columns)) {
                $query->orWhere('order_reference', $gdaOrderId);
                if ($gdaExternalId) {
                    $query->orWhere('order_reference', $gdaExternalId);
                }
            }
            
            if (in_array('reference', $columns)) {
                $query->orWhere('reference', $gdaOrderId);
                if ($gdaExternalId) {
                    $query->orWhere('reference', $gdaExternalId);
                }
            }
        })->first();

        if ($purchase) {
            Log::info('Found purchase by other fields', [
                'purchase_id' => $purchase->id
            ]);
            return $purchase;
        }

        Log::info('No purchase found');
        return null;
    }
}