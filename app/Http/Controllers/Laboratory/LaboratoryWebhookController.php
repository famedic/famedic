<?php
// app/Http/Controllers/Laboratory/LaboratoryWebhookController.php

namespace App\Http\Controllers\Laboratory;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

use App\Actions\Laboratory\CreateNotificationAction;
use App\Actions\Laboratory\FindReferencesAction;
use App\Actions\Laboratory\ProcessNotificationAction;

class LaboratoryWebhookController extends Controller
{
    protected CreateNotificationAction $createNotificationAction;
    protected FindReferencesAction $findReferencesAction;
    protected ProcessNotificationAction $processNotificationAction;

    public function __construct(
        CreateNotificationAction $createNotificationAction,
        FindReferencesAction $findReferencesAction,
        ProcessNotificationAction $processNotificationAction
    ) {
        $this->createNotificationAction = $createNotificationAction;
        $this->findReferencesAction = $findReferencesAction;
        $this->processNotificationAction = $processNotificationAction;
    }

    /**
     * Webhook principal para recibir notificaciones del laboratorio (GDA)
     */
    public function handleNotification(Request $request): JsonResponse
    {
        // LOG 1: Payload completo recibido
        Log::info('===== LABORATORY WEBHOOK RECEIVED =====', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'full_payload' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        // Validación del payload
        $validator = Validator::make($request->all(), [
            'header.lineanegocio' => 'required|string',
            'header.registro' => 'required|string',
            'header.marca' => 'required|integer',
            'resourceType' => 'required|string|in:ServiceRequest,ServiceRequestCotizacion',
            'id' => 'required|string',
            'requisition.system' => 'required|string',
            'requisition.value' => 'required|string',
            'requisition.convenio' => 'required|integer',
            'status' => 'required|string|in:active,completed,in-progress,cancelled',
            'intent' => 'required|string|in:order',
            'code.coding' => 'required|array',
            'code.coding.*.code' => 'required|string',
            'code.coding.*.display' => 'required|string',
            'subject.reference' => 'required|string',
            'GDA_menssage.acuse' => 'sometimes|string|uuid',
        ]);

        if ($validator->fails()) {
            Log::error('===== VALIDATION FAILED =====', [
                'errors' => $validator->errors()->toArray(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            // Buscar referencias
            $references = $this->findReferencesAction->execute($data);

            // Crear notificación
            $notification = $this->createNotificationAction->execute($data, $request, $references);

            // Procesar notificación según su tipo
            $this->processNotificationAction->execute($notification, $data, $references);

            return response()->json([
                'success' => true,
                'message' => 'Notification received and processed successfully',
                'notification_id' => $notification->id,
                'notification_type' => $notification->notification_type,
                'lineanegocio_saved' => $notification->lineanegocio,
                'gda_acuse' => $notification->gda_acuse,
                'related_entities' => [
                    'quote_id' => $references['quote_id'] ?? null,
                    'purchase_id' => $references['purchase_id'] ?? null,
                ],
                'timestamp' => now()->toIso8601String(),
            ], 201);

        } catch (\Exception $e) {
            Log::error('===== ERROR PROCESSING WEBHOOK =====', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing notification',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Health check endpoint
     */
    public function healthCheck(): JsonResponse
    {
        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $purchaseColumns = Schema::getColumnListing('laboratory_purchases');
        $contactColumns = Schema::getColumnListing('contacts');

        return response()->json([
            'success' => true,
            'message' => 'Laboratory webhook endpoint is operational',
            'timestamp' => now()->toIso8601String(),
            'service' => 'Famedic Laboratory Webhook API',
            'version' => '2.0.0',
            'endpoints' => [
                'webhook' => 'POST /api/laboratory/webhook/notifications',
                'health_check' => 'GET /api/laboratory/webhook/health',
                'test_webhook' => 'POST /api/laboratory/webhook/test',
            ],
            'notification_types' => [
                LaboratoryNotification::TYPE_SAMPLE_COLLECTION => 'Notificación de toma de muestra',
                LaboratoryNotification::TYPE_RESULTS => 'Notificación de resultados',
                LaboratoryNotification::TYPE_STATUS_UPDATE => 'Actualización de estado',
                LaboratoryNotification::TYPE_NOTIFICATION => 'Notificación general',
            ],
            'database_info' => [
                'laboratory_quotes_columns' => $quoteColumns,
                'laboratory_purchases_columns' => $purchaseColumns,
                'contacts_columns' => $contactColumns,
            ],
            'note' => 'Sistema refactorizado con Actions. Soporta notificaciones de toma de muestra y resultados.'
        ]);
    }

    /**
     * Test endpoint para el proveedor
     */
    public function testWebhook(Request $request): JsonResponse
    {
        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $purchaseColumns = Schema::getColumnListing('laboratory_purchases');

        $samplePayload = [
            'header' => [
                'lineanegocio' => 'Notificacion-Toma-Muestra',
                'registro' => now()->format('Y-m-d\TH:i:s:000'),
                'marca' => 5,
                'token' => 'test-token'
            ],
            'resourceType' => 'ServiceRequest',
            'id' => 'TEST-SAMPLE-' . now()->timestamp,
            'requisition' => [
                'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                'value' => 'TEST-EXTERNAL-' . uniqid(),
                'convenio' => 99999
            ],
            'status' => 'in-progress',
            'intent' => 'order',
            'code' => [
                'coding' => [
                    [
                        'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                        'code' => '999999',
                        'display' => 'EXAMEN DE PRUEBA'
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/999999'
            ],
            'requester' => [
                'reference' => 'Practitioner/999999',
                'display' => 'MEDICO DE PRUEBA'
            ],
            'GDA_menssage' => [
                'codeHttp' => 200,
                'mensaje' => 'success',
                'descripcion' => 'Notificación de toma de muestra',
                'acuse' => 'test-acuse-' . uniqid()
            ]
        ];

        $resultsPayload = [
            'header' => [
                'lineanegocio' => 'Notificaion-Resultados',
                'registro' => now()->format('Y-m-d\TH:i:s:000'),
                'marca' => 5,
                'token' => 'test-token'
            ],
            'resourceType' => 'ServiceRequest',
            'id' => 'TEST-RESULTS-' . now()->timestamp,
            'requisition' => [
                'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                'value' => 'TEST-EXTERNAL-' . uniqid(),
                'convenio' => 99999
            ],
            'status' => 'completed',
            'intent' => 'order',
            'code' => [
                'coding' => [
                    [
                        'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                        'code' => '999999',
                        'display' => 'EXAMEN DE PRUEBA'
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/999999'
            ],
            'requester' => [
                'reference' => 'Practitioner/999999',
                'display' => 'MEDICO DE PRUEBA'
            ],
            'GDA_menssage' => [
                'codeHttp' => 200,
                'mensaje' => 'success',
                'descripcion' => 'Notificación de resultados',
                'acuse' => 'test-acuse-' . uniqid()
            ],
            'infogda_resultado_b64' => 'JVBERi0...' // Base64 de ejemplo
        ];

        return response()->json([
            'success' => true,
            'message' => 'Test endpoint funcionando',
            'received_payload' => $request->all() ?: 'No payload received',
            'example_payloads' => [
                'sample_collection' => $samplePayload,
                'results' => $resultsPayload,
            ],
            'database_columns' => [
                'laboratory_quotes' => $quoteColumns,
                'laboratory_purchases' => $purchaseColumns,
            ],
            'notification_types' => [
                'sample_collection' => 'Se envía correo de "Toma de muestra realizada" con mensaje de confianza',
                'results' => 'Se envía correo de "Resultados disponibles"',
            ],
            'email_messages' => [
                'sample_collection' => '✅ Toma de muestra realizada - Mensaje tranquilizador: "Estamos para servirte, esperamos verte pronto"',
                'results' => '🔬 Resultados disponibles',
            ]
        ]);
    }
}