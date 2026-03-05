<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use App\Actions\Laboratories\GetGDAResultsAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LaboratoryResultController extends Controller
{
    public function fetch(LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        // LOG 1: Entrada al controller
        Log::info('🔵 [CONTROLLER] fetch() iniciado', [
            'purchase_id' => $laboratoryPurchase->id,
            'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo,
            'gda_order_id' => $laboratoryPurchase->gda_order_id,
            'controller' => 'LaboratoryResultController',
            'method' => 'fetch',
            'time' => now()->toDateTimeString()
        ]);

        // LOG 2: Buscar notificación
        Log::info('🔎 [CONTROLLER] Buscando notificación', [
            'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo
        ]);

        $notification = LaboratoryNotification::where('gda_consecutivo', $laboratoryPurchase->gda_consecutivo)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->latest()
            ->first();

        Log::info('📋 [CONTROLLER] Resultado búsqueda notificación', [
            'found' => $notification ? 'SI' : 'NO',
            'notification_id' => $notification->id ?? null,
            'notification_type' => $notification->notification_type ?? null,
            'has_pdf_cached' => $notification && !empty($notification->results_pdf_base64) ? 'SI' : 'NO'
        ]);

        if (!$notification) {
            Log::warning('⚠️ [CONTROLLER] No se encontró notificación', [
                'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No existe notificación de resultados',
                'debug' => [
                    'purchase_id' => $laboratoryPurchase->id,
                    'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo
                ]
            ], 404);
        }

        // Si ya hay PDF en caché, devolverlo
        if (!empty($notification->results_pdf_base64)) {
            Log::info('✅ [CONTROLLER] Usando PDF en caché', [
                'notification_id' => $notification->id,
                'pdf_size' => strlen($notification->results_pdf_base64)
            ]);

            return response()->json([
                'success' => true,
                'cached' => true,
                'pdf_base64' => $notification->results_pdf_base64
            ]);
        }

        // LOG 3: Antes de llamar al Action
        Log::info('🚀 [CONTROLLER] Llamando a GetGDAResultsAction', [
            'action_class' => GetGDAResultsAction::class,
            'order_id' => $laboratoryPurchase->gda_order_id,
        ]);

        $action = app(GetGDAResultsAction::class);

        try {
            // LOG 4: Ejecutando Action
            Log::info('⏳ [CONTROLLER] Ejecutando action...');

            $response = $action(
                $laboratoryPurchase->gda_order_id,
                $notification->payload
            );

            // LOG 5: Respuesta del Action
            Log::info('📥 [CONTROLLER] Action ejecutado correctamente', [
                'response_keys' => array_keys($response),
                'has_pdf' => isset($response['infogda_resultado_b64']) ? 'SI' : 'NO',
                'gda_acuse' => $response['GDA_menssage']['acuse'] ?? 'NO_ACUSE',
                'gda_mensaje' => $response['GDA_menssage']['mensaje'] ?? 'NO_MENSAJE'
            ]);

        } catch (\Exception $e) {
            // LOG 6: Error en el Action
            Log::error('🔥 [CONTROLLER] Error en GetGDAResultsAction', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace_preview' => array_slice($e->getTrace(), 0, 3)
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error consultando GDA',
                'error' => $e->getMessage()
            ], 500);
        }

        $pdf = $response['infogda_resultado_b64'] ?? null;

        if (!$pdf) {
            Log::warning('⚠️ [CONTROLLER] GDA no devolvió PDF', [
                'response_completa' => $response
            ]);

            return response()->json([
                'success' => false,
                'message' => 'GDA no devolvió resultados',
                'debug' => [
                    'response_keys' => array_keys($response)
                ]
            ], 500);
        }

        // LOG 7: Guardando PDF
        Log::info('💾 [CONTROLLER] Guardando PDF en notificación', [
            'notification_id' => $notification->id,
            'pdf_size_bytes' => strlen($pdf),
            'pdf_size_kb' => round(strlen($pdf) / 1024, 2) . ' KB'
        ]);

        $notification->update([
            'results_pdf_base64' => $pdf
        ]);

        Log::info('✅ [CONTROLLER] Proceso completado exitosamente', [
            'purchase_id' => $laboratoryPurchase->id,
            'notification_id' => $notification->id,
            'total_time' => 'completado'
        ]);

        return response()->json([
            'success' => true,
            'cached' => false,
            'pdf_base64' => $pdf
        ]);
    }
}