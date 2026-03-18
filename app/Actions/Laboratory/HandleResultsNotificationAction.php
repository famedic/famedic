<?php
// app/Actions/Laboratory/HandleResultsNotificationAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class HandleResultsNotificationAction
{
    public function execute(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing results notification', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'purchase_id' => $references['purchase_id'] ?? null,
            'quote_id' => $references['quote_id'] ?? null
        ]);

        $hasResultsInPayload = isset($data['infogda_resultado_b64']) && !empty($data['infogda_resultado_b64']);

        // Actualizar notificación
        $this->updateNotification($notification, $data, $hasResultsInPayload);

        // Actualizar quote
        $quote = $this->updateQuote($references, $data, $hasResultsInPayload);

        // Actualizar purchase
        $purchase = $this->updatePurchase($references, $data, $hasResultsInPayload);

        // Determinar si ya se recibieron todas las notificaciones de la orden
        $shouldSendEmail = $this->shouldSendEmailForPurchase($purchase);

        // Encontrar usuario
        $userToNotify = $this->findUserToNotify($references, $quote, $purchase);

        // Enviar email solo cuando ya se completaron todas las notificaciones de la orden
        if ($shouldSendEmail) {
            $this->sendEmailNotification($userToNotify, $notification, $data, $quote, $purchase, $hasResultsInPayload);
        } else {
            Log::info('Skipping results email until all studies for order have reported', [
                'notification_id' => $notification->id,
                'purchase_id' => $purchase?->id,
            ]);
        }

        // Marcar como procesada
        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }

    /**
     * Determina si ya se recibieron todas las notificaciones de resultados para una orden
     * y por lo tanto es momento de enviar el correo (solo uno por orden).
     */
    protected function shouldSendEmailForPurchase(?LaboratoryPurchase $purchase): bool
    {
        if (! $purchase) {
            // Si no hay compra asociada, mantenemos el comportamiento actual (un correo por notificación)
            return true;
        }

        // Número de estudios de la orden (items de la compra)
        $studiesCount = $purchase->laboratoryPurchaseItems()->count();

        if ($studiesCount === 0) {
            // Si por alguna razón no hay estudios asociados, no bloqueamos el envío
            return true;
        }

        // Número de notificaciones de resultados recibidas para esta orden
        $notificationsCount = LaboratoryNotification::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->whereNotNull('results_received_at')
            ->count();

        Log::info('Checking if should send consolidated results email', [
            'purchase_id' => $purchase->id,
            'studies_count' => $studiesCount,
            'notifications_count' => $notificationsCount,
        ]);

        // Solo enviamos cuando ya tenemos al menos tanta notificación de resultados como estudios
        // (cuando se procese el último estudio, counts serán iguales)
        return $notificationsCount >= $studiesCount;

    }

    protected function updateNotification(LaboratoryNotification $notification, array $data, bool $hasResultsInPayload): void
    {
        $updateData = [
            'gda_status' => 'completed',
            'results_received_at' => now(),
        ];

        if ($hasResultsInPayload) {
            $updateData['results_pdf_base64'] = $data['infogda_resultado_b64'];
        }

        $notification->update($updateData);

        Log::info('Notification updated with results', [
            'notification_id' => $notification->id,
            'has_pdf' => $hasResultsInPayload
        ]);
    }

    protected function updateQuote(array $references, array $data, bool $hasResultsInPayload): ?LaboratoryQuote
    {
        if (empty($references['quote_id'])) {
            return null;
        }

        $quote = LaboratoryQuote::find($references['quote_id']);
        if (!$quote) {
            return null;
        }

        $quoteColumns = Schema::getColumnListing('laboratory_quotes');
        $updates = [];

        // Actualizar campos de GDA
        if (in_array('gda_order_id', $quoteColumns) && empty($quote->gda_order_id)) {
            $updates['gda_order_id'] = $data['id'];
        }

        if (in_array('gda_consecutivo', $quoteColumns) && empty($quote->gda_consecutivo)) {
            $updates['gda_consecutivo'] = $data['id'];
        }

        if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $quoteColumns)) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        // Guardar respuesta completa de GDA
        if (in_array('gda_response', $quoteColumns)) {
            $updates['gda_response'] = $data;
        }

        // Guardar resultados en PDF si vienen
        if ($hasResultsInPayload && in_array('pdf_base64', $quoteColumns)) {
            $updates['pdf_base64'] = $data['infogda_resultado_b64'];
        }

        // Marcar como completado
        if (in_array('completed_at', $quoteColumns)) {
            $updates['completed_at'] = now();
        }

        if (in_array('results_downloaded_at', $quoteColumns)) {
            $updates['results_downloaded_at'] = now();
        }

        // Actualizar status de pago si está pendiente
        if ($quote->status === 'pending_branch_payment' && in_array('status', $quoteColumns)) {
            $updates['status'] = 'paid';

            if (in_array('paid_at', $quoteColumns)) {
                $updates['paid_at'] = now();
            }
        }

        if (!empty($updates)) {
            $quote->update($updates);

            Log::info('Quote updated with results', [
                'quote_id' => $quote->id,
                'updates' => array_keys($updates)
            ]);
        }

        return $quote;
    }

    protected function updatePurchase(array $references, array $data, bool $hasResultsInPayload): ?LaboratoryPurchase
    {
        if (empty($references['purchase_id'])) {
            return null;
        }

        $purchase = LaboratoryPurchase::find($references['purchase_id']);
        if (!$purchase) {
            return null;
        }

        $updates = [];

        // Actualizar campos de GDA si están vacíos
        if (empty($purchase->gda_order_id)) {
            $updates['gda_order_id'] = $data['id'];
        }

        if (empty($purchase->gda_consecutivo)) {
            $updates['gda_consecutivo'] = $data['id'];
        }

        if (isset($data['GDA_menssage']['acuse'])) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        // Guardar respuesta completa de GDA
        $updates['gda_response'] = $data;

        // Actualizar código HTTP y mensaje
        if (isset($data['GDA_menssage']['codeHttp'])) {
            $updates['gda_code_http'] = $data['GDA_menssage']['codeHttp'];
        }

        if (isset($data['GDA_menssage']['mensaje'])) {
            $updates['gda_mensaje'] = $data['GDA_menssage']['mensaje'];
        }

        if (isset($data['GDA_menssage']['descripcion'])) {
            $updates['gda_description'] = $data['GDA_menssage']['descripcion'];
        }

        // Guardar PDF si viene
        if ($hasResultsInPayload) {
            $updates['pdf_base64'] = $data['infogda_resultado_b64'];
        }

        // Marcar timestamps de resultados
        $updates['results_downloaded_at'] = now();
        $updates['completed_at'] = now();

        // Actualizar status
        $updates['status'] = 'completed';

        $purchase->update($updates);

        Log::info('Purchase updated with results', [
            'purchase_id' => $purchase->id,
            'updates' => array_keys($updates),
            'has_pdf' => $hasResultsInPayload
        ]);

        return $purchase;
    }

    protected function findUserToNotify(array $references, $quote, $purchase): ?User
    {
        if ($purchase && $purchase->customer && $purchase->customer->user) {
            return $purchase->customer->user;
        }

        if ($quote && $quote->user) {
            return $quote->user;
        }

        if (!empty($references['user_id'])) {
            return User::find($references['user_id']);
        }

        return null;
    }

    protected function sendEmailNotification(?User $user, LaboratoryNotification $notification, array $data, $quote, $purchase, bool $hasResultsInPayload): void
    {
        if (!$user || empty($user->email)) {
            Log::warning('No user/email found to notify for results', [
                'gda_order_id' => $data['id']
            ]);
            return;
        }

        try {
            $user->notify(new LaboratoryResultsAvailable(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $data['id'],
                hasPdfInPayload: $hasResultsInPayload
            ));

            $notification->update([
                'email_sent_at' => now(),
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send results email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            $notification->update([
                'email_error' => $e->getMessage(),
                'email_attempted_at' => now(),
            ]);
        }
    }
}
