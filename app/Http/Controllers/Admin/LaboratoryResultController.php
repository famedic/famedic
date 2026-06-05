<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LaboratoryResultController extends Controller
{
    public function fetch(LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        Log::info('🔵 [CONTROLLER] fetch() iniciado', [
            'purchase_id' => $laboratoryPurchase->id,
            'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo,
            'gda_order_id' => $laboratoryPurchase->gda_order_id,
        ]);

        $notification = LaboratoryNotification::latestResultsForOrder(
            $laboratoryPurchase->id,
            $laboratoryPurchase->gda_order_id,
            $laboratoryPurchase->gda_consecutivo
        );

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'No existe notificación de resultados',
                'debug' => [
                    'purchase_id' => $laboratoryPurchase->id,
                    'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo
                ]
            ], 404);
        }

        try {
            $result = app(ResolveGdaResultsPdfAction::class)($notification);
        } catch (\Throwable $e) {
            Log::error('🔥 [CONTROLLER] Error en ResolveGdaResultsPdfAction', [
                'error_message' => $e->getMessage(),
                'notification_id' => $notification->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error consultando GDA',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'cached' => $result['cached'],
            'refreshed' => $result['refreshed'],
            'pdf_base64' => $result['pdf_base64'],
        ]);
    }
}
