<?php
// app/Actions/Laboratory/CreateNotificationAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use App\Support\GDA\GdaPayloadSanitizer;
use App\Support\GDA\GdaWebhookPayloadResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreateNotificationAction
{
    public function __construct(
        protected DetermineNotificationTypeAction $determineTypeAction,
        protected GdaWebhookPayloadResolver $payloadResolver,
    ) {
    }

    public function execute(array $data, Request $request, array $references): LaboratoryNotification
    {
        $resolved = $references['gda'] ?? $this->payloadResolver->resolve($data);

        Log::info('GDA webhook normalized identifiers', [
            'service_request_id' => $resolved['service_request_id'] ?? null,
            'requisition_value' => $resolved['requisition_value'] ?? null,
            'infogda_orden' => $resolved['infogda_orden'] ?? null,
            'infogda_etiqueta' => $resolved['infogda_etiqueta'] ?? null,
            'contenedor_acronim' => $resolved['contenedor_acronim'] ?? null,
            'is_gabinete' => $resolved['is_gabinete'] ?? false,
            'normalized_gda_order_id' => $resolved['gda_order_id'] ?? null,
            'normalized_gda_consecutivo' => $resolved['gda_consecutivo'] ?? null,
        ]);

        $notificationType = $this->determineTypeAction->execute(
            $data['status'],
            $data['header']['lineanegocio'] ?? null
        );

        $notificationData = [
            'notification_type' => $notificationType,
            'gda_order_id' => $resolved['gda_order_id'],
            'gda_consecutivo' => $resolved['gda_consecutivo'],
            'gda_external_id' => $resolved['gda_external_id'],
            'gda_acuse' => $resolved['acuse'],
            'gda_status' => $data['status'],
            'resource_type' => $data['resourceType'],
            'payload' => GdaPayloadSanitizer::sanitize($request->all()),
            'lineanegocio' => $data['header']['lineanegocio'] ?? null,
            'gda_message' => $data['GDA_menssage'] ?? null,
            'laboratory_quote_id' => $references['quote_id'] ?? null,
            'laboratory_purchase_id' => $references['purchase_id'] ?? null,
            'user_id' => $references['user_id'] ?? null,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'results_received_at' => $data['status'] === 'completed' ? now() : null,
        ];

        if (! empty($references['contact_id'])) {
            $notificationData['contact_id'] = $references['contact_id'];
        }

        Log::info('Creating notification with data', [
            'has_contact_id' => isset($notificationData['contact_id']),
            'contact_id' => $notificationData['contact_id'] ?? null,
            'notification_type' => $notificationType,
            'gda_order_id' => $notificationData['gda_order_id'],
            'gda_consecutivo' => $notificationData['gda_consecutivo'],
        ]);

        $notification = LaboratoryNotification::create($notificationData);

        Log::info('Notification created', [
            'notification_id' => $notification->id,
            'type' => $notificationType,
            'lineanegocio' => $notification->lineanegocio,
            'saved_contact_id' => $notification->contact_id,
            'gda_order_id' => $notification->gda_order_id,
            'gda_consecutivo' => $notification->gda_consecutivo,
        ]);

        return $notification;
    }
}
