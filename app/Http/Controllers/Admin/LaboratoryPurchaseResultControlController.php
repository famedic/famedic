<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laboratories\AnalyzeLegacyLaboratoryResultsAction;
use App\Actions\Laboratories\RefreshGdaLaboratoryResultAction;
use App\Enums\LaboratoryResultEventType;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Services\Laboratory\LaboratoryResultsNotificationService;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultCompletionService;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultControlPresenter;
use App\Services\LaboratoryResults\LaboratoryResultCompletionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class LaboratoryPurchaseResultControlController
{
    public function refresh(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        RefreshGdaLaboratoryResultAction $refreshAction,
        LaboratoryPurchaseResultControlPresenter $presenter,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $laboratoryPurchase->loadMissing('laboratoryResultStatuses');

        $status = $this->refreshableStatus($laboratoryPurchase);

        if (! $status) {
            return $this->jsonState(
                $request,
                $laboratoryPurchase,
                $presenter,
                false,
                'refresh_not_available',
                'No hay estudios disponibles para actualizar desde GDA en este momento.',
                422
            );
        }

        $this->recordAdminEvent($status, LaboratoryResultEventType::AdminRefreshRequested, $request, [
            'purchase_id' => $laboratoryPurchase->id,
        ]);

        $result = $refreshAction->executeForAdmin($status->id);

        return $this->jsonState(
            $request,
            $laboratoryPurchase->fresh(),
            $presenter,
            (bool) $result['ok'],
            (string) $result['code'],
            (string) $result['message'],
            $result['ok'] ? 200 : 422,
            ['refresh' => $result]
        );
    }

    public function notify(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        LaboratoryPurchaseResultCompletionService $completionService,
        LaboratoryResultsNotificationService $notificationService,
        LaboratoryPurchaseResultControlPresenter $presenter,
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $laboratoryPurchase->loadMissing(['customer.user', 'laboratoryResultStatuses']);
        $status = $this->firstStatus($laboratoryPurchase);

        if (! $status) {
            return $this->jsonState(
                $request,
                $laboratoryPurchase,
                $presenter,
                false,
                'result_status_not_found',
                'No hay control semántico de resultados para enviar aviso.',
                422
            );
        }

        $completion = $completionService->evaluate($laboratoryPurchase);

        $this->recordAdminEvent($status, LaboratoryResultEventType::AdminNotificationRequested, $request, [
            'purchase_id' => $laboratoryPurchase->id,
            'gate_mode' => app(LaboratoryResultCompletionGate::class)->mode(),
        ]);

        if (! $completion->isComplete && ! $completion->legacyFallback) {
            $message = 'No se puede enviar aviso: los resultados no están completos.';
            $this->recordAdminEvent($status, LaboratoryResultEventType::AdminNotificationFailed, $request, [
                'purchase_id' => $laboratoryPurchase->id,
                'reason' => 'semantic_gate_incomplete',
                'counts' => $completion->toLogContext(),
            ]);

            return $this->jsonState($request, $laboratoryPurchase, $presenter, false, 'semantic_gate_incomplete', $message, 422);
        }

        $notification = $this->latestResultsNotification($laboratoryPurchase);

        if (! $notification) {
            $this->recordAdminEvent($status, LaboratoryResultEventType::AdminNotificationFailed, $request, [
                'purchase_id' => $laboratoryPurchase->id,
                'reason' => 'results_notification_not_found',
            ]);

            return $this->jsonState(
                $request,
                $laboratoryPurchase,
                $presenter,
                false,
                'results_notification_not_found',
                'No hay notificación GDA de resultados para reenviar aviso.',
                422
            );
        }

        $sent = $notificationService->notifyPatient(
            user: $laboratoryPurchase->customer?->user,
            notification: $notification,
            quote: null,
            purchase: $laboratoryPurchase,
            gdaOrderId: (string) ($notification->gda_order_id ?: $laboratoryPurchase->gda_order_id),
            hasPdfInPayload: false,
            tagActiveCampaign: false,
        );

        $this->recordAdminEvent(
            $status,
            $sent ? LaboratoryResultEventType::AdminNotificationSent : LaboratoryResultEventType::AdminNotificationFailed,
            $request,
            [
                'purchase_id' => $laboratoryPurchase->id,
                'notification_id' => $notification->id,
                'reason' => $sent ? null : 'email_send_failed',
            ]
        );

        return $this->jsonState(
            $request,
            $laboratoryPurchase->fresh(),
            $presenter,
            $sent,
            $sent ? 'admin_notification_sent' : 'admin_notification_failed',
            $sent ? 'Aviso de resultados enviado sin adjuntar PDF.' : 'No se pudo enviar el aviso de resultados.',
            $sent ? 200 : 422
        );
    }

    public function analyzeLegacy(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        AnalyzeLegacyLaboratoryResultsAction $analyzeAction,
        LaboratoryPurchaseResultControlPresenter $presenter,
    ): JsonResponse {
        $this->authorizeAdmin($request);

        try {
            $analyzeAction->execute($laboratoryPurchase);
        } catch (Throwable $exception) {
            Log::warning('legacy_laboratory_results_analysis_failed', [
                'purchase_id' => $laboratoryPurchase->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->jsonState(
                $request,
                $laboratoryPurchase,
                $presenter,
                false,
                'legacy_pdf_not_available',
                'No hay un PDF histórico disponible para analizar.',
                422
            );
        }

        return $this->jsonState(
            $request,
            $laboratoryPurchase->fresh(),
            $presenter,
            true,
            'legacy_analysis_processed',
            'Resultado histórico analizado sin enviar correos ni ActiveCampaign.'
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->administrator?->hasPermissionTo('laboratory-purchases.manage'),
            403
        );
    }

    private function refreshableStatus(LaboratoryPurchase $purchase): ?LaboratoryResultStatus
    {
        return $purchase->laboratoryResultStatuses()
            ->whereNotIn('status', ['complete', 'manual_review', 'error'])
            ->where(function ($query) {
                $query->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            })
            ->oldest('id')
            ->first();
    }

    private function firstStatus(LaboratoryPurchase $purchase): ?LaboratoryResultStatus
    {
        return $purchase->laboratoryResultStatuses()->oldest('id')->first();
    }

    private function latestResultsNotification(LaboratoryPurchase $purchase): ?LaboratoryNotification
    {
        return LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );
    }

    private function recordAdminEvent(
        LaboratoryResultStatus $status,
        LaboratoryResultEventType $eventType,
        Request $request,
        array $metadata = [],
    ): void {
        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_result_version_id' => $status->versions()->latest('id')->value('id'),
            'event_type' => $eventType,
            'actor_type' => 'user',
            'actor_id' => $request->user()?->id,
            'metadata' => array_filter($metadata, fn ($value) => $value !== null),
            'created_at' => now(),
        ]);
    }

    private function jsonState(
        Request $request,
        LaboratoryPurchase $purchase,
        LaboratoryPurchaseResultControlPresenter $presenter,
        bool $ok,
        string $code,
        string $message,
        int $status = 200,
        array $extra = [],
    ): JsonResponse {
        return response()->json([
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
        ] + $presenter->present($purchase->fresh(), $request->user()) + $extra, $status);
    }
}
