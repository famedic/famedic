<?php
// app/Actions/Laboratory/CreateNotificationAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreateNotificationAction
{
    protected DetermineNotificationTypeAction $determineTypeAction;

    public function __construct(DetermineNotificationTypeAction $determineTypeAction)
    {
        $this->determineTypeAction = $determineTypeAction;
    }

    public function execute(array $data, Request $request, array $references): LaboratoryNotification
    {
        $notificationType = $this->determineTypeAction->execute(
            $data['status'],
            $data['header']['lineanegocio'] ?? null
        );

        // Solo incluir contact_id si no es null
        $notificationData = [
            'notification_type' => $notificationType,
            'gda_order_id' => $data['id'],
            'gda_consecutivo' => $data['id'],
            'gda_external_id' => $data['requisition']['value'] ?? null,
            'gda_acuse' => $data['GDA_menssage']['acuse'] ?? null,
            'gda_status' => $data['status'],
            'resource_type' => $data['resourceType'],
            'payload' => $request->all(),
            'lineanegocio' => $data['header']['lineanegocio'] ?? null,
            'gda_message' => $data['GDA_menssage'] ?? null,
            'laboratory_quote_id' => $references['quote_id'] ?? null,
            'laboratory_purchase_id' => $references['purchase_id'] ?? null,
            'user_id' => $references['user_id'] ?? null,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'results_received_at' => $data['status'] === 'completed' ? now() : null,
        ];

        // Solo agregar contact_id si existe y es válido
        if (!empty($references['contact_id'])) {
            $notificationData['contact_id'] = $references['contact_id'];
        }

        Log::info('Creating notification with data', [
            'has_contact_id' => isset($notificationData['contact_id']),
            'contact_id' => $notificationData['contact_id'] ?? null,
            'notification_type' => $notificationType
        ]);

        $notification = LaboratoryNotification::create($notificationData);

        Log::info('Notification created', [
            'notification_id' => $notification->id,
            'type' => $notificationType,
            'lineanegocio' => $notification->lineanegocio,
            'saved_contact_id' => $notification->contact_id
        ]);

        return $notification;
    }
}