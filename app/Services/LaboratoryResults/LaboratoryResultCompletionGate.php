<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultEventType;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultEvent;
use Illuminate\Support\Facades\Log;

class LaboratoryResultCompletionGate
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_ENFORCED = 'enforced';

    public function __construct(
        private LaboratoryPurchaseResultCompletionService $completionService,
    ) {}

    public function mode(): string
    {
        $mode = strtolower((string) config('services.gda.result_completion_gate.mode', self::MODE_OFF));

        return in_array($mode, [self::MODE_OFF, self::MODE_SHADOW, self::MODE_ENFORCED], true)
            ? $mode
            : self::MODE_OFF;
    }

    public function shouldAllowNotification(
        ?LaboratoryPurchase $purchase,
        bool $legacyReady,
        string $gdaOrderId,
    ): bool {
        $mode = $this->mode();

        if ($mode === self::MODE_OFF) {
            return $legacyReady;
        }

        $completion = $this->completionService->evaluate($purchase);
        $context = [
            'mode' => $mode,
            'purchase_id' => $purchase?->id,
            'gda_order_id' => $gdaOrderId,
            'legacy_ready' => $legacyReady,
        ] + $completion->toLogContext();

        Log::info(
            $mode === self::MODE_SHADOW
                ? 'laboratory_result_completion_gate_shadow'
                : 'laboratory_result_completion_gate_evaluated',
            $context
        );

        $this->recordEvaluationEvent($purchase, $completion, $mode, $legacyReady);

        if ($mode === self::MODE_SHADOW) {
            return $legacyReady;
        }

        if (! $completion->isComplete) {
            Log::info('laboratory_result_notification_suppressed', $context);
            $this->recordSuppressedEvent($purchase, $completion, $mode, $legacyReady);
        }

        return $legacyReady && $completion->isComplete;
    }

    private function recordEvaluationEvent(
        ?LaboratoryPurchase $purchase,
        LaboratoryPurchaseResultCompletion $completion,
        string $mode,
        bool $legacyReady,
    ): void {
        $status = $purchase?->laboratoryResultStatuses()->oldest('id')->first();

        if (! $status) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'event_type' => LaboratoryResultEventType::CompletionGateEvaluated,
            'metadata' => [
                'mode' => $mode,
                'legacy_ready' => $legacyReady,
            ] + $completion->toLogContext(),
            'created_at' => now(),
        ]);
    }

    private function recordSuppressedEvent(
        ?LaboratoryPurchase $purchase,
        LaboratoryPurchaseResultCompletion $completion,
        string $mode,
        bool $legacyReady,
    ): void {
        $status = $purchase?->laboratoryResultStatuses()->oldest('id')->first();

        if (! $status) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'event_type' => LaboratoryResultEventType::NotificationSuppressed,
            'metadata' => [
                'mode' => $mode,
                'legacy_ready' => $legacyReady,
            ] + $completion->toLogContext(),
            'created_at' => now(),
        ]);
    }
}
