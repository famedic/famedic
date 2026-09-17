<?php

namespace App\Services\Laboratory;

use App\Jobs\TagLaboratoryEmailToActiveCampaignJob;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Illuminate\Support\Facades\Log;

class LaboratoryResultsNotificationService
{
    public function notifyPatient(
        ?User $user,
        LaboratoryNotification $notification,
        ?LaboratoryQuote $quote,
        ?LaboratoryPurchase $purchase,
        ?string $gdaOrderId,
        bool $hasPdfInPayload,
        bool $tagActiveCampaign = true,
    ): bool {
        if (! $user || empty($user->email)) {
            Log::warning('No user/email found to notify for results', [
                'gda_order_id' => $gdaOrderId,
            ]);

            return false;
        }

        try {
            $user->notify(new LaboratoryResultsAvailable(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $gdaOrderId,
                hasPdfInPayload: $hasPdfInPayload
            ));

            $notification->update([
                'email_sent_at' => now(),
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
            ]);

            if ($tagActiveCampaign) {
                TagLaboratoryEmailToActiveCampaignJob::dispatch(
                    $user->email,
                    (int) config('services.activecampaign.tag_lab_results_available', 33)
                );

                Log::info('AC: Job de tag (Resultados) despachado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'notification_id' => $notification->id,
                    'gda_order_id' => $gdaOrderId,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send results email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $notification->update([
                'email_error' => $e->getMessage(),
                'email_attempted_at' => now(),
            ]);

            return false;
        }
    }

    public function enqueueActiveCampaignResultsCompleted(?LaboratoryPurchase $purchase): void
    {
        if (! $purchase?->id) {
            return;
        }

        app(ActiveCampaignOutboundDispatcher::class)
            ->enqueueLaboratoryResultsCompleted($purchase->fresh(['customer.user', 'cart']));
    }
}
