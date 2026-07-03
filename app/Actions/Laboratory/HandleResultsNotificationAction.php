<?php
// app/Actions/Laboratory/HandleResultsNotificationAction.php

namespace App\Actions\Laboratory;

use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Jobs\TagLaboratoryEmailToActiveCampaignJob;
use App\Notifications\LaboratoryResultsAvailable;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Support\GDA\GdaPayloadSanitizer;
use App\Support\GDA\GdaWebhookPayloadResolver;
use App\Support\Laboratory\GdaSimulatorSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class HandleResultsNotificationAction
{
    public function __construct(
        protected LabOrderNotificationGateService $notificationGateService,
        protected GdaWebhookPayloadResolver $payloadResolver,
        protected StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
    ) {
    }

    public function execute(LaboratoryNotification $notification, array $data, array $references): void
    {
        Log::info('Processing results notification', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'purchase_id' => $references['purchase_id'] ?? null,
            'quote_id' => $references['quote_id'] ?? null
        ]);

        $pdfBase64FromPayload = GdaPayloadSanitizer::extractResultsPdfBase64($data);
        $hasResultsInPayload = $pdfBase64FromPayload !== null;
        $sanitizedData = GdaPayloadSanitizer::sanitize($data);
        $resolved = $references['gda'] ?? $this->payloadResolver->resolve($data);

        // Actualizar notificación
        $this->updateNotification($notification, $sanitizedData, $hasResultsInPayload);
        $this->invalidateStalePdfCaches($notification);

        // Actualizar quote
        $quote = $this->updateQuote($references, $sanitizedData, $hasResultsInPayload, $resolved);

        // Actualizar purchase
        $purchase = $this->updatePurchase($references, $sanitizedData, $hasResultsInPayload, $resolved);

        $pdfStoredFromWebhook = false;

        if ($hasResultsInPayload && $purchase && empty($purchase->results)) {
            $pdfStoredFromWebhook = $this->storeResultsPdfFromWebhook($purchase, $pdfBase64FromPayload, $notification);
        }

        $this->dispatchResultsPdfSyncJob($purchase, $notification, $pdfStoredFromWebhook);

        $studyExternalId = $this->extractStudyExternalId($sanitizedData);
        $gdaOrderId = $this->payloadResolver->gateOrderId($resolved, $data);

        $gateResult = $this->notificationGateService->registerEvent(
            gdaOrderId: $gdaOrderId,
            eventType: LabOrderNotificationGateService::EVENT_RESULTS,
            purchase: $purchase,
            studyExternalId: $studyExternalId,
            providerEventId: $data['GDA_menssage']['acuse'] ?? null,
            payload: $sanitizedData
        );

        // Encontrar usuario
        $userToNotify = $this->findUserToNotify($references, $quote, $purchase);

        $simulator = $this->simulatorSettings();

        if ($simulator && ! $simulator->sendEmail) {
            Log::info('Results email skipped (GDA simulator: send_email disabled)', [
                'gda_order_id' => $gdaOrderId,
                'notification_id' => $notification->id,
            ]);
        } elseif ($simulator?->bypassGate) {
            $this->sendEmailNotification($userToNotify, $notification, $sanitizedData, $quote, $purchase, $hasResultsInPayload, $resolved, $data);
        } elseif ($gateResult['should_send_results_email']) {
            $wasSent = $this->notificationGateService->sendResultsOnce($gdaOrderId, function () use (
                $userToNotify,
                $notification,
                $sanitizedData,
                $quote,
                $purchase,
                $hasResultsInPayload,
                $resolved,
                $data
            ) {
                $this->sendEmailNotification($userToNotify, $notification, $sanitizedData, $quote, $purchase, $hasResultsInPayload, $resolved, $data);
            });

            if (! $wasSent) {
                Log::info('Results email skipped because it was already sent for order', [
                    'gda_order_id' => $gdaOrderId,
                    'notification_id' => $notification->id,
                ]);
            }
        } else {
            Log::info('Skipping results email because order already notified or event duplicated', [
                'notification_id' => $notification->id,
                'purchase_id' => $purchase?->id,
                'gda_order_id' => $gdaOrderId,
                'is_new_event' => $gateResult['is_new_event'],
            ]);
        }

        // Marcar como procesada
        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }

    protected function storeResultsPdfFromWebhook(
        LaboratoryPurchase $purchase,
        string $pdfBase64,
        LaboratoryNotification $notification
    ): bool {
        try {
            $this->storeGdaResultsPdfToStorageAction->execute(
                $purchase,
                $pdfBase64,
                $notification,
                overwrite: false
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to store GDA results PDF from webhook payload', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            $notification->update([
                'gda_message' => array_merge($notification->gda_message ?? [], [
                    'results_storage_error' => $e->getMessage(),
                    'results_storage_error_at' => now()->toISOString(),
                ]),
            ]);

            return false;
        }
    }

    protected function dispatchResultsPdfSyncJob(
        ?LaboratoryPurchase $purchase,
        LaboratoryNotification $notification,
        bool $pdfStoredFromWebhook
    ): void {
        if (! $purchase?->id) {
            return;
        }

        if ($pdfStoredFromWebhook) {
            Log::info('GDA results PDF sync job skipped: PDF stored from webhook payload', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification->id,
            ]);

            return;
        }

        $purchase->refresh();

        if (! empty($purchase->results) && Storage::exists($purchase->results)) {
            Log::info('GDA results PDF sync job skipped: purchase already has results', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification->id,
                'existing_results' => $purchase->results,
            ]);

            return;
        }

        if (! $notification->hasAvailableResults()) {
            return;
        }

        SyncGdaResultPdfToStorageJob::dispatch($purchase->id, $notification->id)
            ->afterCommit();

        Log::info('GDA results PDF sync job dispatched', [
            'purchase_id' => $purchase->id,
            'notification_id' => $notification->id,
        ]);
    }

    protected function extractStudyExternalId(array $data): ?string
    {
        $code = $data['code']['coding'][0]['code'] ?? null;
        $display = $data['code']['coding'][0]['display'] ?? null;

        if ($code || $display) {
            return trim(($code ?? 'unknown').'|'.($display ?? 'unknown'));
        }

        return $data['requisition']['value'] ?? null;
    }

    protected function invalidateStalePdfCaches(LaboratoryNotification $current): void
    {
        LaboratoryNotification::query()
            ->ofResultsType()
            ->forSameOrderAs($current)
            ->where('id', '!=', $current->id)
            ->whereNotNull('results_pdf_base64')
            ->update(['results_pdf_base64' => null]);
    }

    protected function updateNotification(LaboratoryNotification $notification, array $data, bool $hasResultsInPayload): void
    {
        $updateData = [
            'gda_status' => 'completed',
            'results_received_at' => now(),
        ];

        $notification->update($updateData);

        Log::info('Notification updated with results', [
            'notification_id' => $notification->id,
            'has_pdf' => $hasResultsInPayload
        ]);
    }

    protected function updateQuote(array $references, array $data, bool $hasResultsInPayload, array $resolved): ?LaboratoryQuote
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

        $updates = array_merge(
            $updates,
            $this->payloadResolver->emptyGdaFieldUpdates(
                $resolved,
                $quote->gda_order_id ?? null,
                $quote->gda_consecutivo ?? null
            )
        );

        if (isset($data['GDA_menssage']['acuse']) && in_array('gda_acuse', $quoteColumns)) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        if (in_array('gda_response', $quoteColumns)) {
            $updates['gda_response'] = $data;
        }

        if (in_array('completed_at', $quoteColumns)) {
            $updates['completed_at'] = now();
        }

        if (in_array('results_downloaded_at', $quoteColumns)) {
            $updates['results_downloaded_at'] = now();
        }

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

    protected function updatePurchase(array $references, array $data, bool $hasResultsInPayload, array $resolved): ?LaboratoryPurchase
    {
        if (empty($references['purchase_id'])) {
            return null;
        }

        $purchase = LaboratoryPurchase::find($references['purchase_id']);
        if (!$purchase) {
            return null;
        }

        $updates = [];

        $updates = array_merge(
            $updates,
            $this->payloadResolver->emptyGdaFieldUpdates(
                $resolved,
                $purchase->gda_order_id ?? null,
                $purchase->gda_consecutivo ?? null
            )
        );

        if (isset($data['GDA_menssage']['acuse'])) {
            $updates['gda_acuse'] = $data['GDA_menssage']['acuse'];
        }

        $updates['gda_response'] = $data;

        if (isset($data['GDA_menssage']['codeHttp'])) {
            $updates['gda_code_http'] = $data['GDA_menssage']['codeHttp'];
        }

        if (isset($data['GDA_menssage']['mensaje'])) {
            $updates['gda_mensaje'] = $data['GDA_menssage']['mensaje'];
        }

        if (isset($data['GDA_menssage']['descripcion'])) {
            $updates['gda_description'] = $data['GDA_menssage']['descripcion'];
        }

        $updates['results_downloaded_at'] = now();
        $updates['completed_at'] = now();
        $updates['status'] = 'completed';

        $purchase->update($updates);

        Log::info('Purchase updated with results', [
            'purchase_id' => $purchase->id,
            'updates' => array_keys($updates),
            'has_pdf' => $hasResultsInPayload
        ]);

        return $purchase->fresh();
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

    protected function simulatorSettings(): ?GdaSimulatorSettings
    {
        return app()->bound(GdaSimulatorSettings::class)
            ? app(GdaSimulatorSettings::class)
            : null;
    }

    protected function sendEmailNotification(
        ?User $user,
        LaboratoryNotification $notification,
        array $sanitizedData,
        $quote,
        $purchase,
        bool $hasResultsInPayload,
        array $resolved,
        array $originalData
    ): void {
        if (!$user || empty($user->email)) {
            Log::warning('No user/email found to notify for results', [
                'gda_order_id' => $originalData['id'] ?? null
            ]);
            return;
        }

        try {
            $user->notify(new LaboratoryResultsAvailable(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $this->payloadResolver->gateOrderId($resolved, $originalData),
                hasPdfInPayload: $hasResultsInPayload
            ));

            $notification->update([
                'email_sent_at' => now(),
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
            ]);

            TagLaboratoryEmailToActiveCampaignJob::dispatch(
                $user->email,
                (int) config('services.activecampaign.tag_lab_results_available', 33)
            );

            Log::info('AC: Job de tag (Resultados) despachado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'notification_id' => $notification->id,
                'gda_order_id' => $originalData['id'] ?? null,
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
