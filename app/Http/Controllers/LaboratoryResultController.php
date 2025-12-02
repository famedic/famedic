<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use App\Actions\Laboratories\GetGDAResultsAction;

class LaboratoryResultController extends Controller
{
    /**
     * Mostrar la lista de resultados disponibles del paciente
     */
    public function index()
    {
        $user = Auth::user();

        // Obtener todas las notificaciones del usuario como tabla principal
        $notifications = LaboratoryNotification::where('user_id', $user->id)
            ->with([
                'laboratoryQuote.quoteItems',
                'laboratoryPurchase.laboratoryPurchaseItems',
                'contact'
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($notification) {
                // Determinar si es de quote o purchase
                $relatedEntity = $notification->getRelatedEntity();
                $entityType = $notification->getEntityType();

                // Información común de la notificación
                $baseData = [
                    'notification_id' => $notification->id,
                    'type' => $notification->notification_type,
                    'status' => $notification->status,
                    'gda_status' => $notification->gda_status,
                    'created_at' => $notification->created_at,
                    'results_received_at' => $notification->results_received_at,
                    'read_at' => $notification->read_at,
                    'has_pdf' => !empty($notification->results_pdf_base64),
                    'pdf_base64' => $notification->results_pdf_base64,
                    'gda_acuse' => $notification->gda_acuse,
                    'gda_order_id' => $notification->gda_order_id,
                    'resource_type' => $notification->resource_type,
                    'laboratory_brand' => $notification->laboratory_brand,
                ];

                // Si es de una cotización
                if ($entityType === 'quote' && $notification->laboratoryQuote) {
                    $quote = $notification->laboratoryQuote;
                    return array_merge($baseData, [
                        'entity_type' => 'quote',
                        'entity_id' => $quote->id,
                        'laboratory_brand' => $quote->laboratory_brand,
                        'patient_name' => $quote->patient_full_name,
                        'items' => $quote->quoteItems->map(fn($item) => [
                            'name' => $item->name,
                            'quantity' => $item->quantity ?? 1,
                            'price_cents' => $item->price_cents,
                        ]),
                        'quote_data' => [
                            'total_cents' => $quote->total_cents,
                            'status' => $quote->status,
                            'expires_at' => $quote->expires_at,
                        ]
                    ]);
                }

                // Si es de una compra
                if ($entityType === 'purchase' && $notification->laboratoryPurchase) {
                    $purchase = $notification->laboratoryPurchase;
                    return array_merge($baseData, [
                        'entity_type' => 'purchase',
                        'entity_id' => $purchase->id,
                        'laboratory_brand' => $purchase->brand->value ?? $purchase->brand,
                        'patient_name' => $purchase->full_name,
                        'items' => $purchase->laboratoryPurchaseItems->map(fn($item) => [
                            'name' => $item->name,
                            'quantity' => $item->quantity ?? 1,
                            'price_cents' => $item->price_cents,
                        ]),
                        'purchase_data' => [
                            'total_cents' => $purchase->total_cents,
                            'created_at' => $purchase->created_at,
                        ]
                    ]);
                }

                // Notificación sin entidad relacionada (caso raro)
                return array_merge($baseData, [
                    'entity_type' => 'unknown',
                    'entity_id' => null,
                    'laboratory_brand' => $notification->laboratory_brand,
                    'patient_name' => null,
                    'items' => [],
                ]);
            });

        // Estadísticas para debug
        $stats = [
            'total_notifications' => $notifications->count(),
            'by_type' => $notifications->groupBy('type')->map->count(),
            'by_entity' => $notifications->groupBy('entity_type')->map->count(),
            'with_pdf' => $notifications->where('has_pdf', true)->count(),
            'unread' => $notifications->whereNull('read_at')->count(),
        ];

        logger('🔍 [NOTIFICATIONS DEBUG]', $stats);

        return Inertia::render('LaboratoryResultsList', [
            'notifications' => $notifications,
            'stats' => $stats,
            'hasNotifications' => $notifications->isNotEmpty(),
        ]);
    }

    /**
     * Obtener y guardar resultados PDF desde GDA
     */
    private function fetchAndSaveResults(LaboratoryNotification $notification)
    {
        try {
            // Verificar si ya tenemos resultados
            if (!empty($notification->results_pdf_base64)) {
                return $notification->results_pdf_base64;
            }

            // Obtener información necesaria para la consulta GDA
            $orderId = $notification->gda_order_id;
            $brand = $notification->laboratory_brand;

            if (!$orderId || !$brand) {
                throw new \Exception('Falta información necesaria para obtener resultados');
            }

            // Usar el Action para obtener resultados de GDA
            $gdaAction = app(GetGDAResultsAction::class);
            $results = $gdaAction($orderId, $brand);

            // Verificar si la respuesta contiene el PDF en base64
            if (empty($results['infogda_resultado_b64'])) {
                throw new \Exception('No se encontraron resultados PDF en la respuesta');
            }

            // Guardar el PDF en base64
            $notification->update([
                'results_pdf_base64' => $results['infogda_resultado_b64'],
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'results_fetched_at' => now()->toISOString(),
                    'results_source' => 'gda_api'
                ])
            ]);

            return $results['infogda_resultado_b64'];

        } catch (\Exception $e) {
            logger()->error('Error al obtener resultados de GDA:', [
                'notification_id' => $notification->id,
                'order_id' => $notification->gda_order_id,
                'error' => $e->getMessage()
            ]);

            // Actualizar el mensaje de error
            $notification->update([
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'last_error' => $e->getMessage(),
                    'last_error_at' => now()->toISOString()
                ])
            ]);

            throw $e;
        }
    }

    /**
     * Ver resultado en el navegador
     */
    public function view($type, $id)
    {
        $user = Auth::user();

        // Buscar la notificación basada en el tipo y ID
        $notification = $this->findUserNotification($user, $type, $id);
        
        if (!$notification) {
            abort(404, 'Notificación no encontrada');
        }

        try {
            // Obtener el PDF (si ya existe o obtenerlo de GDA)
            $pdfBase64 = $this->fetchAndSaveResults($notification);
            
            if (!$pdfBase64) {
                abort(404, 'Resultado no disponible');
            }

            // Decodificar base64 y mostrar PDF en el navegador
            $pdfContent = base64_decode($pdfBase64);

            // Marcar como leída
            $notification->markAsRead();

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="resultados_' . $notification->gda_order_id . '.pdf"');

        } catch (\Exception $e) {
            logger()->error('Error en view PDF:', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            
            abort(500, 'Error al cargar el resultado: ' . $e->getMessage());
        }
    }

    /**
     * Descargar resultado
     */
    public function download($type, $id)
    {
        $user = Auth::user();

        // Buscar la notificación basada en el tipo y ID
        $notification = $this->findUserNotification($user, $type, $id);
        
        if (!$notification) {
            abort(404, 'Notificación no encontrada');
        }

        try {
            // Obtener el PDF (si ya existe o obtenerlo de GDA)
            $pdfBase64 = $this->fetchAndSaveResults($notification);
            
            if (!$pdfBase64) {
                abort(404, 'Resultado no disponible');
            }

            // Decodificar base64 y descargar PDF
            $pdfContent = base64_decode($pdfBase64);

            // Marcar como leída
            $notification->markAsRead();

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="resultados_' . $notification->gda_order_id . '.pdf"');

        } catch (\Exception $e) {
            logger()->error('Error en download PDF:', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            
            abort(500, 'Error al descargar el resultado: ' . $e->getMessage());
        }
    }

    /**
     * Buscar notificación del usuario por tipo y ID
     */
    private function findUserNotification($user, $type, $id)
    {
        if ($type === 'quote') {
            return LaboratoryNotification::where('user_id', $user->id)
                ->where('laboratory_quote_id', $id)
                ->whereNotNull('results_received_at') // Solo notificaciones con resultados disponibles
                ->first();
        } else if ($type === 'purchase') {
            return LaboratoryNotification::where('user_id', $user->id)
                ->where('laboratory_purchase_id', $id)
                ->whereNotNull('results_received_at') // Solo notificaciones con resultados disponibles
                ->first();
        } else if ($type === 'notification') {
            return LaboratoryNotification::where('user_id', $user->id)
                ->where('id', $id)
                ->whereNotNull('results_received_at') // Solo notificaciones con resultados disponibles
                ->first();
        }

        return null;
    }

    /**
     * Marcar notificación como leída
     */
    public function markAsRead($notificationId)
    {
        $user = Auth::user();
        
        $notification = LaboratoryNotification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->first();
            
        if ($notification) {
            $notification->markAsRead();
        }
        
        return response()->json(['success' => true]);
    }

    /**
     * Forzar actualización de resultados desde GDA
     */
    public function refreshResults($notificationId)
    {
        $user = Auth::user();
        
        $notification = LaboratoryNotification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->firstOrFail();

        try {
            // Limpiar resultados existentes para forzar nueva descarga
            $notification->update([
                'results_pdf_base64' => null
            ]);

            // Obtener nuevos resultados
            $this->fetchAndSaveResults($notification);

            return response()->json([
                'success' => true,
                'message' => 'Resultados actualizados correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar resultados: ' . $e->getMessage()
            ], 500);
        }
    }
}