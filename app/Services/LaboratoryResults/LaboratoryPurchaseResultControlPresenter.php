<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultStatus;
use App\Models\User;
use Illuminate\Support\Collection;

class LaboratoryPurchaseResultControlPresenter
{
    public function __construct(
        private LaboratoryPurchaseResultCompletionService $completionService,
        private LaboratoryResultCompletionGate $completionGate,
    ) {}

    /**
     * @return array{resultControl: array<string, mixed>, studyResultStatuses: array<int, array<string, mixed>>}
     */
    public function present(LaboratoryPurchase $purchase, ?User $viewer = null): array
    {
        $purchase->loadMissing([
            'laboratoryPurchaseItems.laboratoryResultStatus.versions',
            'laboratoryResultStatuses.versions',
        ]);

        $canManage = (bool) $viewer?->administrator?->hasPermissionTo('laboratory-purchases.manage');
        $completion = $this->completionService->evaluate($purchase);
        $hasStoredResults = filled($purchase->results);
        $legacyAvailable = $hasStoredResults || $purchase->hasResultsAvailable();
        $legacyNoStatuses = $completion->legacyFallback || $purchase->laboratoryResultStatuses->isEmpty();
        $hasSampleCollected = $purchase->hasSampleCollected();
        $overallStatus = $this->overallStatus(
            $completion,
            $legacyAvailable,
            $legacyNoStatuses,
            $hasSampleCollected,
        );

        $resultControl = [
            'overall_status' => $overallStatus,
            'label' => $this->labelForOverallStatus($overallStatus),
            'message' => $this->messageForOverallStatus($overallStatus, $legacyAvailable, $hasSampleCollected),
            'has_sample_collected' => $hasSampleCollected,
            'is_complete' => $completion->isComplete,
            'can_view_results' => $legacyAvailable,
            'button_label' => $completion->isComplete ? 'Ver resultado completo' : 'Ver documento disponible',
            'gate_mode' => $this->completionGate->mode(),
            'legacy_fallback' => $completion->legacyFallback,
            'counts' => $completion->toLogContext(),
            'last_status_at' => $purchase->laboratoryResultStatuses
                ->max(fn (LaboratoryResultStatus $status) => $status->last_checked_at?->getTimestamp())
                ? now()->setTimestamp($purchase->laboratoryResultStatuses->max(fn (LaboratoryResultStatus $status) => $status->last_checked_at?->getTimestamp()))->toIso8601String()
                : null,
            'can_admin_manage' => $canManage,
            'admin_actions' => $canManage ? $this->adminActions($purchase, $completion, $legacyAvailable, $legacyNoStatuses) : null,
        ];

        return [
            'resultControl' => $resultControl,
            'studyResultStatuses' => $this->studyStatuses($purchase, $canManage),
        ];
    }

    private function overallStatus(
        object $completion,
        bool $legacyAvailable,
        bool $legacyNoStatuses,
        bool $hasSampleCollected,
    ): string {
        if ($completion->isComplete) {
            return 'complete';
        }

        if ($legacyAvailable && $legacyNoStatuses) {
            return 'legacy_available';
        }

        if ($completion->manualReview > 0) {
            return 'manual_review';
        }

        if ($completion->error > 0) {
            return 'error';
        }

        if ($completion->pending > 0) {
            return 'pending_interpretation';
        }

        if (! $legacyAvailable && ! $hasSampleCollected) {
            return 'awaiting_sample';
        }

        return $legacyAvailable ? 'legacy_available' : 'pending';
    }

    private function labelForOverallStatus(string $status): string
    {
        return match ($status) {
            'complete' => 'Resultado completo',
            'legacy_available' => 'Resultado disponible',
            'pending_interpretation' => 'Pendiente de interpretación',
            'awaiting_sample' => 'Pendiente de toma de muestra',
            'manual_review' => 'Revisión manual',
            'error' => 'Error de actualización',
            default => 'En proceso',
        };
    }

    private function messageForOverallStatus(string $status, bool $legacyAvailable, bool $hasSampleCollected): string
    {
        return match ($status) {
            'complete' => 'Todos los estudios tienen resultado interpretado.',
            'legacy_available' => 'Hay un PDF de resultados disponible para esta orden.',
            'pending_interpretation' => $legacyAvailable
                ? 'Hay un documento disponible, pero aún falta la interpretación final.'
                : 'El laboratorio ya está procesando tus resultados.',
            'awaiting_sample' => 'El siguiente paso es acudir a tu toma de muestra. Cuando el laboratorio la confirme, aquí verás que tus resultados están en proceso.',
            'manual_review' => 'El resultado requiere revisión del equipo operativo antes de notificarse.',
            'error' => 'No fue posible confirmar el estado final del resultado.',
            default => $hasSampleCollected
                ? 'Ya registramos tu toma de muestra. El laboratorio está procesando tus resultados.'
                : 'Aún no hay resultados completos disponibles para esta orden.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studyStatuses(LaboratoryPurchase $purchase, bool $canManage): array
    {
        /** @var Collection<int, LaboratoryResultStatus> $statuses */
        $statuses = $purchase->laboratoryResultStatuses->keyBy('laboratory_purchase_item_id');

        return $purchase->laboratoryPurchaseItems->map(function ($item) use ($statuses, $canManage): array {
            /** @var LaboratoryResultStatus|null $status */
            $status = $statuses->get($item->id);
            $latestVersion = $status?->versions?->sortByDesc('id')->first();
            $statusValue = $status?->status?->value ?? LaboratoryResultStatusEnum::NotAvailable->value;

            $payload = [
                'id' => $item->id,
                'name' => $item->name,
                'status' => $statusValue,
                'status_label' => $this->labelForItemStatus($statusValue),
                'status_color' => $this->colorForItemStatus($statusValue),
                'classification' => $latestVersion?->classification?->value,
                'updated_at' => $status?->updated_at?->toIso8601String(),
                'last_checked_at' => $status?->last_checked_at?->toIso8601String(),
                'message' => $this->messageForItemStatus($statusValue),
            ];

            if ($canManage) {
                $payload += [
                    'gda_id' => $item->gda_id,
                    'result_status_id' => $status?->id,
                    'next_check_at' => $status?->next_check_at?->toIso8601String(),
                    'check_attempts' => $status?->check_attempts ?? 0,
                    'latest_version_id' => $latestVersion?->id,
                    'latest_sha256_short' => $latestVersion?->sha256 ? substr($latestVersion->sha256, 0, 12) : null,
                    'can_refresh_from_gda' => $status
                        && ! in_array($status->status, [
                            LaboratoryResultStatusEnum::Complete,
                            LaboratoryResultStatusEnum::ManualReview,
                            LaboratoryResultStatusEnum::Error,
                        ], true)
                        && ($status->next_check_at === null || $status->next_check_at->isPast()),
                ];
            }

            return $payload;
        })->values()->all();
    }

    private function labelForItemStatus(string $status): string
    {
        return match ($status) {
            LaboratoryResultStatusEnum::Complete->value => 'Completo',
            LaboratoryResultStatusEnum::PendingInterpretation->value => 'Pendiente interpretación',
            LaboratoryResultStatusEnum::ManualReview->value => 'Revisión manual',
            LaboratoryResultStatusEnum::Error->value => 'Error',
            LaboratoryResultStatusEnum::AvailableUnchecked->value => 'Disponible sin validar',
            default => 'Pendiente',
        };
    }

    private function colorForItemStatus(string $status): string
    {
        return match ($status) {
            LaboratoryResultStatusEnum::Complete->value => 'green',
            LaboratoryResultStatusEnum::PendingInterpretation->value => 'amber',
            LaboratoryResultStatusEnum::ManualReview->value, LaboratoryResultStatusEnum::Error->value => 'red',
            LaboratoryResultStatusEnum::AvailableUnchecked->value => 'blue',
            default => 'slate',
        };
    }

    private function messageForItemStatus(string $status): string
    {
        return match ($status) {
            LaboratoryResultStatusEnum::Complete->value => 'Interpretación final lista.',
            LaboratoryResultStatusEnum::PendingInterpretation->value => 'PDF recibido; falta interpretación final.',
            LaboratoryResultStatusEnum::ManualReview->value => 'Debe revisarse antes de liberar aviso.',
            LaboratoryResultStatusEnum::Error->value => 'No se pudo procesar automáticamente.',
            LaboratoryResultStatusEnum::AvailableUnchecked->value => 'PDF disponible pendiente de validar.',
            default => 'Sin resultado individual registrado.',
        };
    }

    private function adminActions(LaboratoryPurchase $purchase, object $completion, bool $legacyAvailable, bool $legacyNoStatuses): array
    {
        $hasRefreshableStatus = $purchase->laboratoryResultStatuses->contains(function (LaboratoryResultStatus $status): bool {
            return ! in_array($status->status, [
                LaboratoryResultStatusEnum::Complete,
                LaboratoryResultStatusEnum::ManualReview,
                LaboratoryResultStatusEnum::Error,
            ], true) && ($status->next_check_at === null || $status->next_check_at->isPast());
        });

        return [
            'refresh_url' => route('admin.laboratory-purchases.result-control.refresh', $purchase),
            'send_notification_url' => route('admin.laboratory-purchases.result-control.notify', $purchase),
            'analyze_legacy_url' => route('admin.laboratory-purchases.result-control.analyze-legacy', $purchase),
            'can_refresh' => $hasRefreshableStatus,
            'can_send_notification' => $completion->isComplete || ($legacyAvailable && $legacyNoStatuses),
            'can_analyze_legacy' => $legacyAvailable && $legacyNoStatuses,
        ];
    }
}
