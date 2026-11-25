<?php

namespace App\Services\ActiveCampaign;

use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LaboratoryPreparationActiveCampaignSync
{
    public const TRIGGER_CONFIRMATION_READY = 'confirmation_ready';

    public const TRIGGER_PREPARATION_JOB = 'preparation_job';

    public const TRIGGER_PURCHASE_FULFILLED = 'purchase_fulfilled';

    public function __construct(
        private ActiveCampaignOutboundDispatcher $outboundDispatcher,
        private LaboratoryActiveCampaignPayloadBuilder $payloadBuilder,
        private LaboratoryConfirmationCoordinator $confirmationCoordinator,
    ) {}

    public function syncInstructions(LaboratoryPurchase $purchase, string $trigger): void
    {
        try {
            if (! $this->outboundDispatcher->isCartOutboxEnabled()) {
                return;
            }

            if (! $this->shouldSync($purchase, $trigger)) {
                return;
            }

            $purchase->loadMissing(['customer.user', 'cart', 'laboratoryPurchaseItems', 'preparationSummary']);

            if (! $purchase->cart) {
                Log::info('AC lab preparation instructions skipped: purchase without cart', [
                    'laboratory_purchase_id' => $purchase->id,
                    'trigger' => $trigger,
                ]);

                return;
            }

            $fields = $this->payloadBuilder->forPreparationInstructions($purchase);
            if ($fields === []) {
                Log::info('AC lab preparation instructions skipped: empty payload', [
                    'laboratory_purchase_id' => $purchase->id,
                    'trigger' => $trigger,
                ]);

                return;
            }

            $dispatch = $this->outboundDispatcher->enqueueLaboratoryPreparationInstructions($purchase, $fields);

            Log::info('AC lab preparation instructions dispatch queued', [
                'laboratory_purchase_id' => $purchase->id,
                'trigger' => $trigger,
                'dispatch_id' => $dispatch?->id,
                'idempotency_key' => $dispatch?->idempotency_key,
                'text_length' => mb_strlen((string) ($fields['estudios_instrucciones'] ?? '')),
            ]);
        } catch (Throwable $exception) {
            Log::warning('AC lab preparation instructions sync failed', [
                'laboratory_purchase_id' => $purchase->id,
                'trigger' => $trigger,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function shouldSync(LaboratoryPurchase $purchase, string $trigger): bool
    {
        $cohortOwned = $this->confirmationCoordinator->appliesTo($purchase)
            || $this->confirmationCoordinator->hasIntent($purchase);

        return match ($trigger) {
            self::TRIGGER_CONFIRMATION_READY => true,
            self::TRIGGER_PREPARATION_JOB => ! $cohortOwned,
            self::TRIGGER_PURCHASE_FULFILLED => ! $cohortOwned
                && ! (bool) config('services.laboratory_preparation.deterministic_v3_enabled', false),
            default => false,
        };
    }
}