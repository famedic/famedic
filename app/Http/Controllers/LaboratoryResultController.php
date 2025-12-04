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
     * Obtener y guardar resultados PDF desde GDA (VERSIÓN SIMPLIFICADA)
     */
    private function fetchAndSaveResults(LaboratoryNotification $notification)
    {
        try {
            // Verificar si ya tenemos resultados
            if (!empty($notification->results_pdf_base64)) {
                logger('✅ Resultados ya presentes en notificación', [
                    'notification_id' => $notification->id,
                    'order_id' => $notification->gda_order_id
                ]);
                return $notification->results_pdf_base64;
            }

            // Obtener información necesaria
            $orderId = $notification->gda_order_id;
            $payload = $notification->payload; // ← ¡YA TENEMOS EL PAYLOAD!

            if (!$orderId) {
                throw new \Exception('Falta el ID de orden GDA');
            }
            
            if (!$payload || !is_array($payload)) {
                // Intentar decodificar si es JSON string
                if (is_string($notification->payload)) {
                    $payload = json_decode($notification->payload, true);
                }
                
                if (!$payload || !is_array($payload)) {
                    throw new \Exception('No se pudo obtener el payload de la notificación');
                }
            }

            // Verificar datos esenciales en el payload
            $marca = $payload['header']['marca'] ?? null;
            $convenio = $payload['requisition']['convenio'] ?? null;
            
            logger('🔍 Datos extraídos del payload:', [
                'notification_id' => $notification->id,
                'order_id' => $orderId,
                'marca' => $marca,
                'convenio' => $convenio,
                'id_en_payload' => $payload['id'] ?? 'NO_ID'
            ]);

            if (!$marca) {
                throw new \Exception('No se encontró la marca en el payload');
            }
            
            if (!$convenio) {
                throw new \Exception('No se encontró el convenio en el payload');
            }

            // Usar el Action modificado
            logger('🚀 Llamando a GetGDAResultsAction con payload...', [
                'order_id' => $orderId,
                'marca' => $marca,
                'convenio' => $convenio
            ]);
            
            $gdaAction = app(GetGDAResultsAction::class);
            $results = $gdaAction($orderId, $payload);

            // Verificar si la respuesta contiene el PDF
            if (empty($results['infogda_resultado_b64'])) {
                throw new \Exception('No se encontraron resultados PDF en la respuesta');
            }

            // Guardar el PDF en base64
            $notification->update([
                'results_pdf_base64' => $results['infogda_resultado_b64'],
                'laboratory_brand' => $this->extractBrandFromPayload($payload), // Opcional
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'results_fetched_at' => now()->toISOString(),
                    'results_source' => 'gda_api',
                    'marca_used' => $marca,
                    'convenio_used' => $convenio
                ])
            ]);

            logger('✅ Resultados guardados exitosamente:', [
                'notification_id' => $notification->id,
                'order_id' => $orderId,
                'pdf_size' => strlen($results['infogda_resultado_b64'])
            ]);

            return $results['infogda_resultado_b64'];

        } catch (\Exception $e) {
            logger()->error('❌ Error al obtener resultados de GDA:', [
                'notification_id' => $notification->id,
                'order_id' => $notification->gda_order_id,
                'has_payload' => !empty($payload),
                'marca' => $marca ?? 'NO_DEFINIDO',
                'convenio' => $convenio ?? 'NO_DEFINIDO',
                'error_message' => $e->getMessage()
            ]);

            // Actualizar el mensaje de error
            $notification->update([
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'last_error' => $e->getMessage(),
                    'last_error_at' => now()->toISOString(),
                    'marca_attempted' => $marca ?? null,
                    'convenio_attempted' => $convenio ?? null
                ])
            ]);

            throw $e;
        }
    }

    /**
     * Extraer brand del payload (opcional, para referencia)
     */
    private function extractBrandFromPayload(array $payload): ?string
    {
        $marca = $payload['header']['marca'] ?? null;
        
        if (!$marca) {
            return null;
        }
        
        // Mapeo simple (puedes ajustarlo)
        $map = [
            1 => 'olab',
            4 => 'azteca',
            5 => 'swisslab',
            7 => 'jenner',
            15 => 'liacsa',
            6 => 'famedic',
        ];
        
        return $map[$marca] ?? null;
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
            logger('❌ Notificación no encontrada para view:', [
                'user_id' => $user->id,
                'type' => $type,
                'id' => $id
            ]);
            abort(404, 'Notificación no encontrada');
        }

        logger('🔍 Intentando view PDF:', [
            'notification_id' => $notification->id,
            'order_id' => $notification->gda_order_id,
            'has_pdf' => !empty($notification->results_pdf_base64)
        ]);

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

            logger('✅ PDF enviado al navegador:', [
                'notification_id' => $notification->id,
                'order_id' => $notification->gda_order_id,
                'content_length' => strlen($pdfContent)
            ]);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="resultados_' . $notification->gda_order_id . '.pdf"');

        } catch (\Exception $e) {
            logger()->error('❌ Error en view PDF:', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            logger('❌ Notificación no encontrada para download:', [
                'user_id' => $user->id,
                'type' => $type,
                'id' => $id
            ]);
            abort(404, 'Notificación no encontrada');
        }

        logger('🔍 Intentando download PDF:', [
            'notification_id' => $notification->id,
            'order_id' => $notification->gda_order_id,
            'has_pdf' => !empty($notification->results_pdf_base64)
        ]);

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

            logger('✅ PDF preparado para descarga:', [
                'notification_id' => $notification->id,
                'order_id' => $notification->gda_order_id,
                'content_length' => strlen($pdfContent)
            ]);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="resultados_' . $notification->gda_order_id . '.pdf"');

        } catch (\Exception $e) {
            logger()->error('❌ Error en download PDF:', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            abort(500, 'Error al descargar el resultado: ' . $e->getMessage());
        }
    }

    /**
     * Buscar notificación del usuario por tipo y ID
     */
    private function findUserNotification($user, $type, $id)
    {
        logger('🔎 Buscando notificación:', [
            'user_id' => $user->id,
            'type' => $type,
            'id' => $id
        ]);

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

        logger('⚠️ Tipo de notificación desconocido:', ['type' => $type]);
        return null;
    }

    /**
     * Método de diagnóstico para ver notificación específica
     */
    public function debugNotification($notificationId)
    {
        $user = Auth::user();
        
        $notification = LaboratoryNotification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->with(['laboratoryQuote', 'laboratoryPurchase'])
            ->first();
        
        if (!$notification) {
            return response()->json(['error' => 'Notificación no encontrada'], 404);
        }
        
        // === DEBUG EXTENDIDO ===
        $response = [
            'notification' => [
                'id' => $notification->id,
                'gda_order_id' => $notification->gda_order_id,
                'laboratory_brand' => $notification->laboratory_brand,
                'laboratory_quote_id' => $notification->laboratory_quote_id,
                'laboratory_purchase_id' => $notification->laboratory_purchase_id,
                'results_pdf_base64' => $notification->results_pdf_base64 ? 'PRESENTE (' . strlen($notification->results_pdf_base64) . ' bytes)' : 'AUSENTE',
                'results_received_at' => $notification->results_received_at,
                'gda_acuse' => $notification->gda_acuse,
                'created_at' => $notification->created_at,
                'payload_sample' => $notification->payload ? json_decode($notification->payload, true)['header'] ?? 'NO PAYLOAD' : 'NO PAYLOAD',
            ],
            'quote' => $notification->laboratoryQuote ? [
                'id' => $notification->laboratoryQuote->id,
                'laboratory_brand' => $notification->laboratoryQuote->laboratory_brand,
                'status' => $notification->laboratoryQuote->status,
                'gda_order_id' => $notification->laboratoryQuote->gda_order_id,
                'gda_acuse' => $notification->laboratoryQuote->gda_acuse,
            ] : null,
            'purchase' => $notification->laboratoryPurchase ? [
                'id' => $notification->laboratoryPurchase->id,
                'brand' => $notification->laboratoryPurchase->brand,
                'status' => $notification->laboratoryPurchase->status,
                'gda_order_id' => $notification->laboratoryPurchase->gda_order_id,
                'gda_acuse' => $notification->laboratoryPurchase->gda_acuse,
            ] : null,
            'config_check' => [
                'brand_in_config' => $notification->laboratory_brand ? config('services.gda.brands.' . $notification->laboratory_brand) ? 'SÍ' : 'NO' : 'NO BRAND',
                'available_brands' => array_keys(config('services.gda.brands', [])),
            ]
        ];
        
        logger('🔬 DEBUG NOTIFICATION:', $response);
        
        return response()->json($response);
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

            logger('🔄 Forzando actualización de resultados:', [
                'notification_id' => $notificationId,
                'order_id' => $notification->gda_order_id
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