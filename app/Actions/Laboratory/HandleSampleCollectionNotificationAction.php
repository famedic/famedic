<?php
// app/Actions/Laboratory/HandleSampleCollectionNotificationAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Jobs\TagLaboratoryEmailToActiveCampaignJob;
use App\Notifications\LaboratorySampleCollected;
use App\Services\Laboratory\LabOrderNotificationGateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class HandleSampleCollectionNotificationAction
{
    public function __construct(
        protected LabOrderNotificationGateService $notificationGateService
    ) {
    }

    public function execute(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing sample collection notification', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'purchase_id' => $references['purchase_id'] ?? null,
            'quote_id' => $references['quote_id'] ?? null
        ]);

        // Actualizar la notificación
        $notification->update([
            'gda_status' => $data['status'],
            'results_received_at' => null,
        ]);

        // Actualizar quote si existe
        $quote = $this->updateQuote($references, $data);
        
        // Actualizar purchase si existe
        $purchase = $this->updatePurchase($references, $data);

        $studyExternalId = $this->extractStudyExternalId($data);
        $gdaOrderId = (string) ($data['id'] ?? '');

        $gateResult = $this->notificationGateService->registerEvent(
            gdaOrderId: $gdaOrderId,
            eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
            purchase: $purchase,
            studyExternalId: $studyExternalId,
            providerEventId: $data['GDA_menssage']['acuse'] ?? null,
            payload: $data
        );

        // Encontrar usuario para notificar
        $userToNotify = $this->findUserToNotify($references, $quote, $purchase);

        if ($gateResult['should_send_sample_email']) {
            $wasSent = $this->notificationGateService->sendSampleOnce($gdaOrderId, function () use (
                $userToNotify,
                $notification,
                $data,
                $quote,
                $purchase
            ) {
                $this->sendEmailNotification($userToNotify, $notification, $data, $quote, $purchase);
            });

            if (! $wasSent) {
                Log::info('Sample email skipped because it was already sent for order', [
                    'gda_order_id' => $gdaOrderId,
                    'notification_id' => $notification->id,
                ]);
            }
        } else {
            Log::info('Sample email pending until all studies are notified', [
                'gda_order_id' => $gdaOrderId,
                'notification_id' => $notification->id,
                'is_new_event' => $gateResult['is_new_event'],
                'sample_received_count' => $gateResult['state']->sample_received_count,
                'total_studies' => max(1, (int) $gateResult['state']->total_studies),
            ]);
        }

        // Marcar como procesada
        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }

    protected function extractStudyExternalId(array $data): ?string
    {
        $code = $data['code']['coding'][0]['code'] ?? null;
        $display = $data['code']['coding'][0]['display'] ?? null;

        if ($code || $display) {
            return trim(($code ?? 'unknown') . '|' . ($display ?? 'unknown'));
        }

        return $data['requisition']['value'] ?? null;
    }

    protected function updateQuote(array $references, array $data): ?LaboratoryQuote
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

        // Actualizar campos de GDA en quote
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

        // Actualizar código HTTP y mensaje
        if (isset($data['GDA_menssage']['codeHttp']) && in_array('gda_code_http', $quoteColumns)) {
            $updates['gda_code_http'] = $data['GDA_menssage']['codeHttp'];
        }

        if (isset($data['GDA_menssage']['mensaje']) && in_array('gda_mensaje', $quoteColumns)) {
            $updates['gda_mensaje'] = $data['GDA_menssage']['mensaje'];
        }

        if (isset($data['GDA_menssage']['descripcion']) && in_array('gda_description', $quoteColumns)) {
            $updates['gda_description'] = $data['GDA_menssage']['descripcion'];
        }

        // Marcar como "ready" o "sample collected" según lo que uses
        if (in_array('ready_at', $quoteColumns)) {
            $updates['ready_at'] = now();
        }

        if (!empty($updates)) {
            $quote->update($updates);
            
            Log::info('Quote updated with sample collection', [
                'quote_id' => $quote->id,
                'updates' => array_keys($updates)
            ]);
        }

        return $quote;
    }

    protected function updatePurchase(array $references, array $data): ?LaboratoryPurchase
    {
        if (empty($references['purchase_id'])) {
            return null;
        }

        $purchase = LaboratoryPurchase::find($references['purchase_id']);
        if (!$purchase) {
            return null;
        }

        $updates = [];

        // Actualizar campos de GDA
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

        // Marcar como ready_at (indica que ya se tomó la muestra)
        $updates['ready_at'] = now();

        // Actualizar status interno si es necesario
        if ($purchase->status === 'pending') {
            $updates['status'] = 'processing'; // O el status que uses para "en proceso"
        }

        $purchase->update($updates);

        Log::info('Purchase updated with sample collection', [
            'purchase_id' => $purchase->id,
            'updates' => array_keys($updates),
            'ready_at' => now()->toDateTimeString()
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

    protected function sendEmailNotification(?User $user, LaboratoryNotification $notification, array $data, $quote, $purchase): void
    {
        if (!$user) {
            Log::warning('No user found to notify for sample collection', [
                'gda_order_id' => $data['id'],
                'notification_id' => $notification->id
            ]);

            $notification->update([
                'notes' => 'Sample collection processed but no user found to notify'
            ]);
            return;
        }

        if (empty($user->email)) {
            Log::warning('User has no email for sample collection', [
                'user_id' => $user->id,
                'gda_order_id' => $data['id']
            ]);

            $notification->update([
                'email_error' => 'User has no email address',
                'email_attempted_at' => now(),
            ]);
            return;
        }

        try {
            $user->notify(new LaboratorySampleCollected(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $data['id']
            ));

            Log::info('Sample collection email sent', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'gda_order_id' => $data['id']
            ]);

            $notification->update([
                'email_sent_at' => now(),
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
            ]);

            TagLaboratoryEmailToActiveCampaignJob::dispatch(
                $user->email,
                (int) config('services.activecampaign.tag_lab_sample_collected', 32)
            );

            Log::info('AC: Job de tag (Toma de muestra) despachado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'notification_id' => $notification->id,
                'gda_order_id' => $data['id'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send sample collection email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'gda_order_id' => $data['id']
            ]);

            $notification->update([
                'email_error' => $e->getMessage(),
                'email_attempted_at' => now(),
            ]);
        }
    }
}