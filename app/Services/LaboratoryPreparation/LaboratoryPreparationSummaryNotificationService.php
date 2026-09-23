<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\InAppNotification;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Notifications\LaboratoryPreparationSummaryAvailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LaboratoryPreparationSummaryNotificationService
{
    public function notifyIfNeeded(LaboratoryPurchasePreparationSummary $summary): bool
    {
        if ($summary->status !== LaboratoryPurchasePreparationSummary::STATUS_GENERATED) {
            return false;
        }

        if ($summary->invalidated_at !== null) {
            return false;
        }

        $shouldSendMail = false;
        $lockedSummary = null;
        $user = null;
        $purchase = null;

        DB::transaction(function () use ($summary, &$lockedSummary, &$user, &$purchase, &$shouldSendMail) {
            $lockedSummary = LaboratoryPurchasePreparationSummary::query()
                ->whereKey($summary->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSummary || $lockedSummary->notified_at !== null) {
                return;
            }

            if (
                $lockedSummary->status !== LaboratoryPurchasePreparationSummary::STATUS_GENERATED
                || $lockedSummary->invalidated_at !== null
            ) {
                return;
            }

            $purchase = $lockedSummary->laboratoryPurchase()
                ->with('customer.user')
                ->first();
            $user = $purchase?->customer?->user;

            if (! $purchase || ! $user) {
                Log::warning('laboratory_preparation_summary_notification_skipped_missing_user', [
                    'summary_id' => $lockedSummary->id,
                    'purchase_id' => $lockedSummary->laboratory_purchase_id,
                ]);

                return;
            }

            $folio = filled($purchase->gda_order_id)
                ? (string) $purchase->gda_order_id
                : '#'.$purchase->id;
            $patient = trim((string) $purchase->full_name);
            $patientLabel = $patient !== '' ? $patient : 'Paciente no especificado';

            InAppNotification::query()->create([
                'user_id' => $user->id,
                'type' => 'laboratory_preparation_summary_ready',
                'title' => 'Resumen de indicaciones disponible',
                'message' => "Folio: {$folio} · Paciente: {$patientLabel}. Ya puedes consultar el resumen de preparación de tu orden.",
                'action_url' => route('laboratory-purchases.show', $purchase, false),
                'is_read' => false,
            ]);

            $lockedSummary->forceFill([
                'notified_at' => now(),
            ])->save();

            $shouldSendMail = true;
        });

        if (! $shouldSendMail || ! $lockedSummary || ! $user || ! $purchase) {
            return false;
        }

        try {
            $user->notify(new LaboratoryPreparationSummaryAvailable(
                $purchase,
                $lockedSummary->refresh(),
            ));

            $lockedSummary->forceFill([
                'notification_email_queued_at' => now(),
            ])->save();

            Log::info('laboratory_preparation_summary_notification_sent', [
                'summary_id' => $lockedSummary->id,
                'purchase_id' => $purchase->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('laboratory_preparation_summary_notification_mail_failed', [
                'summary_id' => $lockedSummary->id,
                'purchase_id' => $purchase->id,
                'user_id' => $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return true;
    }
}
