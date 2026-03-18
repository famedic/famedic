<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use App\Actions\Laboratories\GetGDAResultsAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LaboratoryResultsController extends Controller
{
    public function fetch(Request $request, $purchaseId): JsonResponse
    {
        // LOG INICIAL - Esto debe aparecer SIEMPRE si la ruta existe
        Log::info('🚀 ENTRÓ AL CONTROLLER', [
            'purchaseId' => $purchaseId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_id' => auth()->id() ?? 'guest',
            'headers' => $request->headers->all()
        ]);

        try {
            Log::info('🔵 [PATIENT] Consulta de resultados iniciada', [
                'purchase_id' => $purchaseId,
                'user_id' => auth()->id(),
                'url' => $request->url(),
                'method' => $request->method()
            ]);

            // Buscar manualmente la compra
            $laboratoryPurchase = LaboratoryPurchase::where('id', $purchaseId)->first();

            if (!$laboratoryPurchase) {
                Log::warning('⚠️ Compra no encontrada', [
                    'purchase_id' => $purchaseId,
                    'user_id' => auth()->id()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el pedido de laboratorio'
                ], 404);
            }

            // Verificar que el usuario tenga permiso para ver esta compra
            /*if ($laboratoryPurchase->user_id !== auth()->id()) {
                Log::warning('🚫 Usuario no autorizado', [
                    'purchase_user_id' => $laboratoryPurchase->user_id,
                    'auth_user_id' => auth()->id()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No autorizado'
                ], 403);
            }*/

            /*
            |--------------------------------------------------------------------------
            | Buscar notificación de resultados
            |--------------------------------------------------------------------------
            */

            $notification = LaboratoryNotification::where(
                'gda_consecutivo',
                $laboratoryPurchase->gda_consecutivo
            )
                ->where('lineanegocio', LaboratoryNotification::TYPE_RESULTS)
                ->latest()
                ->first();

            if (!$notification) {
                Log::warning('⚠️ No existe notificación de resultados', [
                    'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Los resultados aún no están disponibles'
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | PLAN A: PDF ya está en base de datos
            |--------------------------------------------------------------------------
            */

            if ($notification->hasResults()) {
                Log::info('✅ Usando PDF almacenado en DB', [
                    'notification_id' => $notification->id
                ]);

                return response()->json([
                    'success' => true,
                    'cached' => true,
                    'pdf_base64' => $notification->results_pdf_base64
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | PLAN B: Consultar API de GDA
            |--------------------------------------------------------------------------
            */

            if (!$notification->needsPdfFetch()) {
                Log::warning('⚠️ Resultados aún no disponibles en GDA', [
                    'notification_id' => $notification->id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Resultados aún no disponibles'
                ], 404);
            }

            try {
                Log::info('🚀 Consultando resultados en GDA', [
                    'gda_order_id' => $laboratoryPurchase->gda_order_id
                ]);

                $action = app(GetGDAResultsAction::class);
                $response = $action(
                    $laboratoryPurchase->gda_order_id,
                    $notification->payload
                );

            } catch (\Exception $e) {
                Log::error('🔥 Error consultando GDA', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error consultando resultados: ' . $e->getMessage()
                ], 500);
            }

            $pdf = $response['infogda_resultado_b64'] ?? null;

            if (!$pdf) {
                Log::warning('⚠️ GDA respondió pero sin PDF', [
                    'response' => $response
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Resultados aún no disponibles'
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Guardar PDF en DB (cache)
            |--------------------------------------------------------------------------
            */

            $notification->update([
                'results_pdf_base64' => $pdf
            ]);

            Log::info('💾 PDF guardado en base de datos', [
                'notification_id' => $notification->id,
                'pdf_size_kb' => round(strlen($pdf) / 1024, 2)
            ]);

            return response()->json([
                'success' => true,
                'cached' => false,
                'pdf_base64' => $pdf
            ]);

        } catch (\Exception $e) {
            Log::error('🔥 Error inesperado en LaboratoryResultsController', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }
}