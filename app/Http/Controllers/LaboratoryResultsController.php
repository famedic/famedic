<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\EnsureLatestGdaResultsPdfAction;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LaboratoryResultsController extends Controller
{
    public function fetch(Request $request, $purchaseId): JsonResponse
    {
        Log::info('🚀 ENTRÓ AL CONTROLLER', [
            'purchaseId' => $purchaseId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_id' => auth()->id() ?? 'guest',
        ]);

        try {
            Log::info('🔵 [PATIENT] Consulta de resultados iniciada', [
                'purchase_id' => $purchaseId,
                'user_id' => auth()->id(),
            ]);

            $laboratoryPurchase = LaboratoryPurchase::where('id', $purchaseId)->first();

            if (!$laboratoryPurchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el pedido de laboratorio'
                ], 404);
            }

            if (! empty($laboratoryPurchase->results) && Storage::exists($laboratoryPurchase->results)) {
                app(EnsureLatestGdaResultsPdfAction::class)->execute(
                    $laboratoryPurchase,
                    'patient_automatic_fetch'
                );

                return response()->json([
                    'success' => true,
                    'cached' => true,
                    'refreshed' => false,
                    'results_url' => route('laboratory-purchases.results', ['laboratory_purchase' => $laboratoryPurchase->id]),
                    'storage_path' => $laboratoryPurchase->results,
                ]);
            }

            $notification = LaboratoryNotification::latestResultsForOrder(
                $laboratoryPurchase->id,
                $laboratoryPurchase->gda_order_id,
                $laboratoryPurchase->gda_consecutivo
            );

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los resultados aún no están disponibles'
                ], 404);
            }

            try {
                $result = app(ResolveGdaResultsPdfAction::class)($notification);
            } catch (\Throwable $e) {
                Log::error('🔥 Error consultando GDA', [
                    'purchase_id' => $purchaseId,
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error consultando resultados: '.$e->getMessage()
                ], 500);
            }

            return response()->json([
                'success' => true,
                'cached' => $result['cached'],
                'refreshed' => $result['refreshed'],
                'pdf_base64' => $result['pdf_base64'],
                'results_url' => ! empty($result['storage_path'])
                    ? route('laboratory-purchases.results', ['laboratory_purchase' => $laboratoryPurchase->id])
                    : null,
                'storage_path' => $result['storage_path'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('🔥 Error inesperado en LaboratoryResultsController', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }
}
