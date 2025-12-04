<?php

namespace App\Http\Controllers\Laboratory;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use App\Notifications\LaboratoryResultsAvailable;

class LaboratoryWebhookController extends Controller
{
    /**
     * Webhook principal para recibir notificaciones del laboratorio (GDA)
     */
    public function handleNotification(Request $request): JsonResponse
    {
        Log::info('Laboratory webhook received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $request->all()
        ]);

        // Validación del payload
        $validator = Validator::make($request->all(), [
            'header.lineanegocio' => 'required|string',
            'header.registro' => 'required|string',
            'header.marca' => 'required|integer',
            'resourceType' => 'required|string|in:ServiceRequest,ServiceRequestCotizacion',
            'id' => 'required|string', // gda_order_id
            'requisition.system' => 'required|string',
            'requisition.value' => 'required|string', // gda_external_id
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
            Log::error('Laboratory webhook validation failed', [
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
            // Determinar tipo de notificación basado en el status
            $notificationType = $this->determineNotificationType($data['status']);

            // Buscar referencias existentes (basado en modelos reales)
            $references = $this->findRelatedReferences($data);

            Log::info('References found for webhook', $references);

            // Crear la notificación
            $notification = LaboratoryNotification::create([
                'notification_type' => $notificationType,
                'gda_order_id' => $data['id'],
                'gda_external_id' => $data['requisition']['value'] ?? null,
                'gda_acuse' => $data['GDA_menssage']['acuse'] ?? null,
                'gda_status' => $data['status'],
                'resource_type' => $data['resourceType'],
                'payload' => $request->all(),
                'gda_message' => $data['GDA_menssage'] ?? null,
                'laboratory_quote_id' => $references['quote_id'] ?? null,
                'laboratory_purchase_id' => $references['purchase_id'] ?? null,
                'user_id' => $references['user_id'] ?? null,
                'contact_id' => $references['contact_id'] ?? null,
                'status' => LaboratoryNotification::STATUS_RECEIVED,
                'results_received_at' => now()
            ]);

            Log::info('Laboratory notification saved', [
                'notification_id' => $notification->id,
                'gda_order_id' => $data['id'],
                'type' => $notificationType,
                'quote_id' => $references['quote_id'] ?? 'not_found',
                'purchase_id' => $references['purchase_id'] ?? 'not_found'
            ]);

            // Procesar según el tipo de notificación
            $this->processNotification($notification, $data, $references);

            return response()->json([
                'success' => true,
                'message' => 'Notification received and processed successfully',
                'notification_id' => $notification->id,
                'gda_acuse' => $notification->gda_acuse,
                'related_entities' => [
                    'quote_id' => $references['quote_id'] ?? null,
                    'purchase_id' => $references['purchase_id'] ?? null,
                ],
                'timestamp' => now()->toIso8601String(),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error processing laboratory webhook', [
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
     * Determina el tipo de notificación basado en el estado
     */
    private function determineNotificationType(string $status): string
    {
        return match ($status) {
            'active' => LaboratoryNotification::TYPE_NOTIFICATION,
            'completed' => LaboratoryNotification::TYPE_RESULTS,
            'in-progress', 'cancelled' => LaboratoryNotification::TYPE_STATUS_UPDATE,
            default => LaboratoryNotification::TYPE_NOTIFICATION
        };
    }

    /**
     * Busca referencias relacionadas basado en modelos reales
     */
    private function findRelatedReferences(array $data): array
    {
        $references = [];
        $gdaOrderId = $data['id']; // Ej: "FB0L122455"
        $gdaExternalId = $data['requisition']['value'] ?? null; // Ej: "MDPROD-412924"
        $gdaAcuse = $data['GDA_menssage']['acuse'] ?? null; // Ej: "fe62d1c6-4059-4330-a93f-b6a435374166"

        Log::info('Searching references for', [
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_acuse' => $gdaAcuse
        ]);

        // ESTRATEGIA 1: Buscar primero en LaboratoryQuote
        $quote = $this->findQuote($gdaOrderId, $gdaExternalId, $gdaAcuse);

        if ($quote) {
            Log::info('Found quote', [
                'quote_id' => $quote->id,
                'found_by' => 'various_criteria',
                'laboratory_purchase_id' => $quote->laboratory_purchase_id
            ]);

            $references['quote_id'] = $quote->id;

            // Si la quote tiene un purchase asociado
            if ($quote->laboratory_purchase_id) {
                $purchase = LaboratoryPurchase::find($quote->laboratory_purchase_id);
                if ($purchase) {
                    $references['purchase_id'] = $purchase->id;
                    $references['user_id'] = $purchase->customer->user_id ?? null;
                    $references['contact_id'] = $purchase->customer->id ?? null;
                }
            } else {
                // Si quote no tiene purchase, usar datos de la quote
                $references['user_id'] = $quote->user_id;
                $references['contact_id'] = $quote->contact_id;
            }
        }

        // ESTRATEGIA 2: Si no se encontró quote o purchase, buscar directamente en purchases
        if (empty($references['purchase_id'])) {
            $purchase = $this->findPurchase($gdaOrderId, $gdaExternalId, $gdaAcuse);

            if ($purchase) {
                Log::info('Found purchase directly', [
                    'purchase_id' => $purchase->id,
                    'purchase_gda_order_id' => $purchase->gda_order_id
                ]);

                $references['purchase_id'] = $purchase->id;
                $references['user_id'] = $purchase->customer->user_id ?? null;
                $references['contact_id'] = $purchase->customer->id ?? null;

                // Buscar quote relacionada con este purchase
                $relatedQuote = LaboratoryQuote::where('laboratory_purchase_id', $purchase->id)->first();
                if ($relatedQuote && empty($references['quote_id'])) {
                    $references['quote_id'] = $relatedQuote->id;
                }
            }
        }

        // ESTRATEGIA 3: Extraer ID de paciente de subject.reference y buscar contacto
        if (!empty($data['subject']['reference']) && empty($references['contact_id'])) {
            $patientId = $this->extractPatientId($data['subject']['reference']);
            if ($patientId) {
                $contact = $this->findContactByPatientId($patientId);
                if ($contact) {
                    $references['contact_id'] = $contact->id;
                    $references['user_id'] = $contact->user_id;

                    Log::info('Found contact', [
                        'contact_id' => $contact->id,
                        'patient_id' => $patientId
                    ]);
                }
            }
        }

        Log::info('Final references found', $references);

        return $references;
    }

    /**
     * Busca quote por múltiples criterios (verificando columnas existentes)
     */
    private function findQuote(string $gdaOrderId, ?string $gdaExternalId, ?string $gdaAcuse): ?LaboratoryQuote
    {
        // Obtener columnas reales de la tabla
        $columns = Schema::getColumnListing('laboratory_quotes');
        Log::info('Available columns in laboratory_quotes', $columns);

        // Primero por gda_acuse (si existe la columna)
        if ($gdaAcuse && in_array('gda_acuse', $columns)) {
            $quote = LaboratoryQuote::where('gda_acuse', $gdaAcuse)->first();
            if ($quote) {
                Log::info('Found quote by gda_acuse', ['gda_acuse' => $gdaAcuse, 'quote_id' => $quote->id]);
                return $quote;
            }
        }

        // Luego por gda_order_id (si existe la columna)
        if (in_array('gda_order_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_order_id', $gdaOrderId)->first();
            if ($quote) {
                Log::info('Found quote by gda_order_id', ['gda_order_id' => $gdaOrderId, 'quote_id' => $quote->id]);
                return $quote;
            }
        }

        // Si no, por gda_external_id (SOLO si existe la columna)
        if ($gdaExternalId && in_array('gda_external_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_external_id', $gdaExternalId)->first();
            if ($quote) {
                Log::info('Found quote by gda_external_id', ['gda_external_id' => $gdaExternalId, 'quote_id' => $quote->id]);
                return $quote;
            }
        }

        // Si no, por gda_quote_id (si existe la columna)
        if (in_array('gda_quote_id', $columns)) {
            $quote = LaboratoryQuote::where('gda_quote_id', $gdaOrderId)->first();
            if ($quote) {
                Log::info('Found quote by gda_quote_id', ['gda_quote_id' => $gdaOrderId, 'quote_id' => $quote->id]);
                return $quote;
            }
        }

        // También buscar por purchase_id (puede que gdaOrderId sea un purchase_id)
        if (in_array('purchase_id', $columns)) {
            $quote = LaboratoryQuote::where('purchase_id', $gdaOrderId)->first();
            if ($quote) {
                Log::info('Found quote by purchase_id', ['purchase_id' => $gdaOrderId, 'quote_id' => $quote->id]);
                return $quote;
            }
        }

        Log::info('No quote found for criteria', [
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_acuse' => $gdaAcuse
        ]);

        return null;
    }

    /**
     * Busca purchase por múltiples criterios (verificando columnas existentes)
     */
    private function findPurchase(string $gdaOrderId, ?string $gdaExternalId, ?string $gdaAcuse): ?LaboratoryPurchase
    {
        // Obtener columnas reales de la tabla
        $columns = Schema::getColumnListing('laboratory_purchases');
        Log::info('Available columns in laboratory_purchases', $columns);

        // Primero buscar por gda_acuse si la columna existe
        if ($gdaAcuse && in_array('gda_acuse', $columns)) {
            $purchase = LaboratoryPurchase::where('gda_acuse', $gdaAcuse)->first();
            if ($purchase) {
                Log::info('Found purchase by gda_acuse', ['gda_acuse' => $gdaAcuse, 'purchase_id' => $purchase->id]);
                return $purchase;
            }
        }

        // Luego por gda_order_id (si existe la columna)
        if (in_array('gda_order_id', $columns)) {
            $purchase = LaboratoryPurchase::where('gda_order_id', $gdaOrderId)->first();
            if ($purchase) {
                Log::info('Found purchase by gda_order_id', ['gda_order_id' => $gdaOrderId, 'purchase_id' => $purchase->id]);
                return $purchase;
            }
        }

        // Si no, por gda_external_id (SOLO si existe la columna)
        if ($gdaExternalId && in_array('gda_external_id', $columns)) {
            $purchase = LaboratoryPurchase::where('gda_external_id', $gdaExternalId)->first();
            if ($purchase) {
                Log::info('Found purchase by gda_external_id', ['gda_external_id' => $gdaExternalId, 'purchase_id' => $purchase->id]);
                return $purchase;
            }
        }

        // Si no, buscar por otros campos posibles
        $purchase = LaboratoryPurchase::where(function ($query) use ($gdaOrderId, $gdaExternalId, $columns) {
            // Buscar por id (poco probable pero por si acaso)
            $query->where('id', $gdaOrderId);

            // Buscar por order_reference si existe
            if (in_array('order_reference', $columns)) {
                $query->orWhere('order_reference', $gdaOrderId);
                if ($gdaExternalId) {
                    $query->orWhere('order_reference', $gdaExternalId);
                }
            }

            // Buscar por reference si existe
            if (in_array('reference', $columns)) {
                $query->orWhere('reference', $gdaOrderId);
                if ($gdaExternalId) {
                    $query->orWhere('reference', $gdaExternalId);
                }
            }
        })->first();

        if ($purchase) {
            Log::info('Found purchase by other fields', [
                'search_terms' => [$gdaOrderId, $gdaExternalId],
                'purchase_id' => $purchase->id
            ]);
            return $purchase;
        }

        Log::info('No purchase found for criteria', [
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_acuse' => $gdaAcuse
        ]);

        return null;
    }

    /**
     * Busca contacto por ID de paciente (versión segura que verifica columnas)
     */
    private function findContactByPatientId(string $patientId): ?Contact
    {
        // Obtener columnas reales de la tabla contacts
        $columns = Schema::getColumnListing('contacts');
        Log::info('Available columns in contacts', $columns);

        // Intentar diferentes estrategias de búsqueda
        $contact = null;

        // 1. Buscar por external_patient_id si existe
        if (in_array('external_patient_id', $columns)) {
            $contact = Contact::where('external_patient_id', $patientId)->first();
            if ($contact) {
                Log::info('Found contact by external_patient_id', [
                    'patient_id' => $patientId,
                    'contact_id' => $contact->id
                ]);
                return $contact;
            }
        }

        // 2. Buscar por gda_patient_id si existe
        if (in_array('gda_patient_id', $columns)) {
            $contact = Contact::where('gda_patient_id', $patientId)->first();
            if ($contact) {
                Log::info('Found contact by gda_patient_id', [
                    'patient_id' => $patientId,
                    'contact_id' => $contact->id
                ]);
                return $contact;
            }
        }

        // 3. Buscar por patient_id si existe
        if (in_array('patient_id', $columns)) {
            $contact = Contact::where('patient_id', $patientId)->first();
            if ($contact) {
                Log::info('Found contact by patient_id', [
                    'patient_id' => $patientId,
                    'contact_id' => $contact->id
                ]);
                return $contact;
            }
        }

        // 4. Buscar por external_id si existe
        if (in_array('external_id', $columns)) {
            $contact = Contact::where('external_id', $patientId)->first();
            if ($contact) {
                Log::info('Found contact by external_id', [
                    'patient_id' => $patientId,
                    'contact_id' => $contact->id
                ]);
                return $contact;
            }
        }

        // 5. Buscar en otros campos que puedan contener el ID
        $contact = Contact::where(function ($query) use ($patientId, $columns) {
            // Buscar en notes si existe
            if (in_array('notes', $columns)) {
                $query->orWhere('notes', 'LIKE', '%' . $patientId . '%');
            }

            // Buscar en metadata si existe
            if (in_array('metadata', $columns)) {
                $query->orWhere('metadata', 'LIKE', '%' . $patientId . '%');
            }

            // Buscar en additional_info si existe
            if (in_array('additional_info', $columns)) {
                $query->orWhere('additional_info', 'LIKE', '%' . $patientId . '%');
            }
        })->first();

        if ($contact) {
            Log::info('Found contact by text search in notes/metadata', [
                'patient_id' => $patientId,
                'contact_id' => $contact->id
            ]);
            return $contact;
        }

        Log::info('No contact found for patient_id', ['patient_id' => $patientId]);
        return null;
    }

    /**
     * Extrae el ID de paciente de la referencia
     */
    private function extractPatientId(string $reference): ?string
    {
        if (preg_match('/Patient\/(\d+)/', $reference, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Procesa la notificación según su tipo
     */
    private function processNotification(LaboratoryNotification $notification, array $data, array $references): void
    {
        try {
            switch ($notification->notification_type) {
                case LaboratoryNotification::TYPE_RESULTS:
                    $this->handleResultsNotification($notification, $data, $references);
                    break;

                case LaboratoryNotification::TYPE_STATUS_UPDATE:
                    $this->handleStatusUpdate($notification, $data, $references);
                    break;

                default:
                    // Para notificaciones generales, solo registrar
                    Log::info('General notification processed', [
                        'notification_id' => $notification->id,
                        'gda_order_id' => $data['id'],
                        'status' => $data['status']
                    ]);
                    break;
            }

            // Marcar como procesada (si no se marcó en los métodos específicos)
            if ($notification->status === LaboratoryNotification::STATUS_RECEIVED) {
                $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
            }

            Log::info('Notification processed successfully', [
                'notification_id' => $notification->id,
                'type' => $notification->notification_type
            ]);

        } catch (\Exception $e) {
            Log::error('Error in processNotification', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $notification->update(['status' => LaboratoryNotification::STATUS_ERROR]);
        }
    }

    /**
     * Maneja notificaciones de resultados
     */
    private function handleResultsNotification(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing results notification', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'purchase_id' => $references['purchase_id'] ?? null,
            'quote_id' => $references['quote_id'] ?? null,
            'user_id' => $references['user_id'] ?? null,
            'current_quote_status' => $references['current_quote_status'] ?? null // ← Status actual de la quote
        ]);

        // Verificar si el payload tiene el PDF en base64
        $hasResultsInPayload = isset($data['infogda_resultado_b64']) && !empty($data['infogda_resultado_b64']);

        // ACTUALIZAR LA NOTIFICACIÓN CON EL TIMESTAMP
        $notificationUpdateData = [
            'gda_status' => 'completed',
            'results_received_at' => now(), // Siempre actualizar
        ];

        if ($hasResultsInPayload) {
            $notificationUpdateData['results_pdf_base64'] = $data['infogda_resultado_b64'];

            Log::info('PDF results saved in notification', [
                'notification_id' => $notification->id,
                'has_pdf' => true,
                'pdf_size_bytes' => strlen($data['infogda_resultado_b64'])
            ]);
        } else {
            Log::info('No PDF in payload, only status update', [
                'notification_id' => $notification->id
            ]);
        }

        // Actualizar la notificación
        $notification->update($notificationUpdateData);

        // ===== LÓGICA PARA ACTUALIZAR STATUS DE PAGO EN SUCURSAL =====
        $quoteStatusUpdated = false;
        $quote = null;

        // Actualizar quote si existe
        if (!empty($references['quote_id'])) {
            $quote = LaboratoryQuote::find($references['quote_id']);
            if ($quote) {
                // Obtener columnas de quotes
                $quoteColumns = Schema::getColumnListing('laboratory_quotes');

                $quoteUpdates = [
                    'gda_status' => 'completed',
                ];

                // Usar columna existente para timestamp
                if (in_array('results_downloaded_at', $quoteColumns)) {
                    $quoteUpdates['results_downloaded_at'] = now();
                } elseif (in_array('ready_at', $quoteColumns)) {
                    $quoteUpdates['ready_at'] = now();
                }

                // Actualizar gda_acuse si viene en el payload Y la columna existe
                if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $quoteColumns)) {
                    $quoteUpdates['gda_acuse'] = $data['GDA_menssage']['acuse'];
                }

                // Guardar gda_order_id si no está guardado
                if (empty($quote->gda_order_id) && in_array('gda_order_id', $quoteColumns)) {
                    $quoteUpdates['gda_order_id'] = $data['id'];
                }

                // ===== ACTUALIZAR STATUS DE PAGO SI ES PENDIENTE EN SUCURSAL =====
                $currentStatus = $quote->status;
                $isPendingBranchPayment = $currentStatus === 'pending_branch_payment';

                if ($isPendingBranchPayment && in_array('status', $quoteColumns)) {
                    // Cambiar status a "paid" (o "completed", "paid", etc.)
                    // IMPORTANTE: Usa el status correcto que usa tu sistema
                    $quoteUpdates['status'] = 'paid'; // ← Cambia esto al status correcto
                    $quoteStatusUpdated = true;

                    Log::info('✅ Quote payment status updated (branch payment)', [
                        'quote_id' => $quote->id,
                        'old_status' => $currentStatus,
                        'new_status' => 'paid',
                        'gda_order_id' => $data['id'],
                        'reason' => 'GDA notification received - payment confirmed at branch'
                    ]);
                }

                // Actualizar timestamp de pago si se actualizó el status
                if ($quoteStatusUpdated) {
                    if (in_array('paid_at', $quoteColumns)) {
                        $quoteUpdates['paid_at'] = now();
                    } elseif (in_array('completed_at', $quoteColumns)) {
                        $quoteUpdates['completed_at'] = now();
                    }
                }

                if ($hasResultsInPayload && in_array('results_pdf_base64', $quoteColumns)) {
                    $quoteUpdates['results_pdf_base64'] = $data['infogda_resultado_b64'];
                }

                if (in_array('gda_results_response', $quoteColumns)) {
                    $quoteUpdates['gda_results_response'] = $data;
                }

                $quote->update($quoteUpdates);

                Log::info('Quote updated with results', [
                    'quote_id' => $quote->id,
                    'has_pdf' => $hasResultsInPayload,
                    'has_acuse' => isset($data['GDA_menssage']['acuse']),
                    'payment_status_updated' => $quoteStatusUpdated,
                    'previous_status' => $currentStatus,
                    'updated_columns' => array_keys($quoteUpdates)
                ]);
            }
        }

        // Buscar usuario para notificar
        $userToNotify = null;
        $purchase = null;

        // 1. Intentar obtener usuario desde purchase
        if (!empty($references['purchase_id'])) {
            $purchase = LaboratoryPurchase::with(['customer.user'])->find($references['purchase_id']);
            if ($purchase && $purchase->customer && $purchase->customer->user) {
                $userToNotify = $purchase->customer->user;
                Log::info('User found via purchase', [
                    'user_id' => $userToNotify->id,
                    'purchase_id' => $purchase->id
                ]);
            }
        }

        // 2. Si no se encontró, intentar desde quote
        if (!$userToNotify && !empty($references['quote_id'])) {
            $quoteForUser = $quote ?? LaboratoryQuote::with(['user'])->find($references['quote_id']);
            if ($quoteForUser && $quoteForUser->user) {
                $userToNotify = $quoteForUser->user;
                Log::info('User found via quote', [
                    'user_id' => $userToNotify->id,
                    'quote_id' => $quoteForUser->id
                ]);
            }
        }

        // 3. Si no se encontró, intentar directamente por user_id
        if (!$userToNotify && !empty($references['user_id'])) {
            $userToNotify = User::find($references['user_id']);
            if ($userToNotify) {
                Log::info('User found via direct user_id', [
                    'user_id' => $userToNotify->id
                ]);
            }
        }

        // Actualizar purchase si existe
        if ($purchase) {
            $updates = [
                'gda_status' => 'completed',
            ];

            // Verificar columnas disponibles en purchases
            $purchaseColumns = Schema::getColumnListing('laboratory_purchases');

            // Usar columna existente para timestamp
            if (in_array('results_downloaded_at', $purchaseColumns)) {
                $updates['results_downloaded_at'] = now();
            } elseif (in_array('ready_at', $purchaseColumns)) {
                $updates['ready_at'] = now();
            } elseif (in_array('completed_at', $purchaseColumns)) {
                $updates['completed_at'] = now();
            }

            // Si el modelo tiene gda_acuse, actualizarlo
            if (in_array('gda_acuse', $purchaseColumns) && isset($data['GDA_menssage']['acuse'])) {
                $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
            }

            // Si tiene columna para PDF, guardarlo
            if ($hasResultsInPayload && in_array('results_pdf_base64', $purchaseColumns)) {
                $updates['results_pdf_base64'] = $data['infogda_resultado_b64'];
            }

            $purchase->update($updates);

            Log::info('Purchase updated with results', [
                'purchase_id' => $purchase->id,
                'status' => 'completed',
                'has_pdf' => $hasResultsInPayload,
                'has_acuse' => isset($data['GDA_menssage']['acuse'])
            ]);
        }

        // ========= ENVIAR NOTIFICACIÓN POR EMAIL =========
        if ($userToNotify) {
            try {
                // Verificar que el usuario tenga email
                if (empty($userToNotify->email)) {
                    Log::warning('User has no email address', [
                        'user_id' => $userToNotify->id,
                        'gda_order_id' => $data['id']
                    ]);

                    $notification->update([
                        'email_error' => 'User has no email address',
                        'email_attempted_at' => now(),
                    ]);

                    return;
                }

                Log::info('Attempting to send email notification', [
                    'user_id' => $userToNotify->id,
                    'user_email' => $userToNotify->email,
                    'gda_order_id' => $data['id'],
                    'quote_status_updated' => $quoteStatusUpdated
                ]);

                // Enviar notificación (sincrónico)
                $userToNotify->notify(new LaboratoryResultsAvailable(
                    $purchase,
                    $quote,
                    $data['id'],
                    $hasResultsInPayload,
                    $quoteStatusUpdated // ← Pasar si se actualizó el pago
                ));

                Log::info('✅ Email notification sent successfully', [
                    'user_id' => $userToNotify->id,
                    'user_email' => $userToNotify->email,
                    'gda_order_id' => $data['id'],
                    'has_pdf_in_payload' => $hasResultsInPayload,
                    'payment_updated' => $quoteStatusUpdated,
                    'sent_at' => now()->toDateTimeString()
                ]);

                // Registrar en la notificación que se envió el email
                $notification->update([
                    'email_sent_at' => now(),
                    'email_recipient_id' => $userToNotify->id,
                    'email_recipient_email' => $userToNotify->email,
                    'status' => LaboratoryNotification::STATUS_PROCESSED,
                ]);

            } catch (\Exception $e) {
                Log::error('❌ Failed to send email notification', [
                    'user_id' => $userToNotify->id,
                    'user_email' => $userToNotify->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'gda_order_id' => $data['id']
                ]);

                // Registrar el error pero marcar como procesado
                $notification->update([
                    'email_error' => $e->getMessage(),
                    'email_attempted_at' => now(),
                    'status' => LaboratoryNotification::STATUS_PROCESSED,
                ]);
            }
        } else {
            Log::warning('No user found to notify for results', [
                'gda_order_id' => $data['id'],
                'references' => $references
            ]);

            // Marcar como procesado aunque no se pudo notificar al usuario
            $notification->update([
                'status' => LaboratoryNotification::STATUS_PROCESSED,
                'notes' => 'Results processed but no user found to notify'
            ]);
        }
    }


    /**
     * Maneja actualizaciones de estado
     */
    private function handleStatusUpdate(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing status update', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'status' => $data['status'],
            'gda_acuse' => $data['GDA_menssage']['acuse'] ?? null
        ]);

        // Actualizar la notificación con el nuevo estado
        $notification->update([
            'gda_status' => $data['status'],
            'results_received_at' => $data['status'] === 'completed' ? now() : null,
        ]);

        // Actualizar purchase si existe
        if (!empty($references['purchase_id'])) {
            $purchase = LaboratoryPurchase::find($references['purchase_id']);
            if ($purchase) {
                $purchaseUpdates = [
                    'gda_status' => $data['status'],
                ];

                // Actualizar gda_acuse si viene en el payload Y la columna existe
                if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', Schema::getColumnListing('laboratory_purchases'))) {
                    $purchaseUpdates['gda_acuse'] = $data['GDA_menssage']['acuse'];
                }

                // Si es cancelado, actualizar cancelled_at si existe
                if ($data['status'] === 'cancelled' && in_array('cancelled_at', Schema::getColumnListing('laboratory_purchases'))) {
                    $purchaseUpdates['cancelled_at'] = now();
                }

                $purchase->update($purchaseUpdates);

                Log::info('Purchase status updated', [
                    'purchase_id' => $purchase->id,
                    'new_gda_status' => $data['status'],
                    'has_acuse' => isset($data['GDA_menssage']['acuse'])
                ]);
            }
        }

        // Actualizar quote si existe
        if (!empty($references['quote_id'])) {
            $quote = LaboratoryQuote::find($references['quote_id']);
            if ($quote) {
                $quoteColumns = Schema::getColumnListing('laboratory_quotes');

                $quoteUpdates = [
                    'gda_status' => $data['status'],
                ];

                // Actualizar gda_acuse si la columna existe
                if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $quoteColumns)) {
                    $quoteUpdates['gda_acuse'] = $data['GDA_menssage']['acuse'];
                }

                // Si es cancelado y la columna existe, marcar como expirado
                if ($data['status'] === 'cancelled' && in_array('expires_at', $quoteColumns)) {
                    $quoteUpdates['expires_at'] = now();
                }

                $quote->update($quoteUpdates);

                Log::info('Quote status updated', [
                    'quote_id' => $quote->id,
                    'new_gda_status' => $data['status'],
                    'has_acuse' => isset($data['GDA_menssage']['acuse'])
                ]);
            }
        }

        // Marcar como procesado
        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }

    /**
     * Health check endpoint
     */
    public function healthCheck(): JsonResponse
    {
        // Obtener columnas reales para logging
        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $purchaseColumns = Schema::getColumnListing('laboratory_purchases');
        $contactColumns = Schema::getColumnListing('contacts');

        return response()->json([
            'success' => true,
            'message' => 'Laboratory webhook endpoint is operational',
            'timestamp' => now()->toIso8601String(),
            'service' => 'Famedic Laboratory Webhook API',
            'version' => '1.0.0',
            'endpoints' => [
                'webhook' => 'POST /api/laboratory/webhook/notifications',
                'health_check' => 'GET /api/laboratory/webhook/health',
                'test_webhook' => 'POST /api/laboratory/webhook/test',
            ],
            'database_info' => [
                'laboratory_quotes_columns' => $quoteColumns,
                'laboratory_purchases_columns' => $purchaseColumns,
                'contacts_columns' => $contactColumns,
            ],
            'webhook_format' => 'GDA FHIR-like JSON',
            'note' => 'El sistema verificará dinámicamente las columnas disponibles antes de realizar búsquedas'
        ]);
    }

    /**
     * Test endpoint para el proveedor
     */
    public function testWebhook(Request $request): JsonResponse
    {
        // Obtener columnas reales para mostrar en respuesta
        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $purchaseColumns = Schema::getColumnListing('laboratory_purchases');
        $contactColumns = Schema::getColumnListing('contacts');

        $testPayload = [
            'header' => [
                'lineanegocio' => 'Notificasion-Resultados',
                'registro' => now()->format('Y-m-d\TH:i:s:000'),
                'marca' => 5,
                'token' => 'test-token'
            ],
            'resourceType' => 'ServiceRequest',
            'id' => 'TEST-' . now()->timestamp,
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
                'descripcion' => 'Notificación de prueba',
                'acuse' => 'test-acuse-' . uniqid()
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'Test endpoint funcionando',
            'received_payload' => $request->all() ?: 'No payload received',
            'example_payload' => $testPayload,
            'database_columns' => [
                'laboratory_quotes' => $quoteColumns,
                'laboratory_purchases' => $purchaseColumns,
                'contacts' => $contactColumns,
            ],
            'expected_response' => [
                'success' => true,
                'message' => 'Notification received and processed successfully',
                'notification_id' => '[auto-generated]',
                'gda_acuse' => '[from payload]',
                'timestamp' => '[ISO 8601]'
            ],
            'note' => 'El sistema verificará dinámicamente qué columnas existen antes de buscar'
        ]);
    }
}