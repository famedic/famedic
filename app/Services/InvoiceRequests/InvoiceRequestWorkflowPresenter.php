<?php

namespace App\Services\InvoiceRequests;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestStatusLog;

class InvoiceRequestWorkflowPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public function presentForAdmin(InvoiceRequest $invoiceRequest): ?array
    {
        $invoiceRequest->loadMissing([
            'statusLogs' => fn ($query) => $query->latest('created_at'),
        ]);

        return [
            'workflow_status' => $invoiceRequest->workflow_status?->value,
            'workflow_status_label' => $this->workflowStatusLabel($invoiceRequest->workflow_status),
            'activated_by' => $invoiceRequest->activated_by,
            'activated_by_label' => $this->activationTriggerLabel($invoiceRequest->activated_by),
            'formatted_requested_at' => $invoiceRequest->formatted_created_at,
            'formatted_submitted_to_billing_at' => $invoiceRequest->formatted_submitted_to_billing_at,
            'formatted_sample_completed_at' => $invoiceRequest->formatted_sample_completed_at,
            'status_logs' => $invoiceRequest->statusLogs
                ->map(fn (InvoiceRequestStatusLog $log) => [
                    'id' => $log->id,
                    'from_status' => $log->from_status?->value,
                    'to_status' => $log->to_status?->value,
                    'trigger' => $log->trigger?->value,
                    'trigger_label' => $this->statusLogTriggerLabel($log->trigger),
                    'event_label' => $this->statusLogEventLabel($log),
                    'formatted_created_at' => localizedDate($log->created_at)?->isoFormat('D MMM Y h:mm a'),
                ])
                ->values()
                ->all(),
        ];
    }

    private function statusLogEventLabel(InvoiceRequestStatusLog $log): string
    {
        return match ($log->trigger) {
            InvoiceRequestStatusLogTrigger::Created => 'Solicitud registrada',
            InvoiceRequestStatusLogTrigger::SampleWebhook => $log->to_status === InvoiceRequestWorkflowStatus::SubmittedToBilling
                ? 'Enviada a facturación'
                : 'Toma completada',
            InvoiceRequestStatusLogTrigger::ResultAvailable => 'Enviada a facturación',
            InvoiceRequestStatusLogTrigger::AdminManual => 'Enviada a facturación',
            InvoiceRequestStatusLogTrigger::Reconciliation => 'Enviada a facturación',
            default => $this->workflowStatusLabel($log->to_status) ?? 'Actualización',
        };
    }

    private function workflowStatusLabel(?InvoiceRequestWorkflowStatus $status): ?string
    {
        return match ($status) {
            InvoiceRequestWorkflowStatus::AwaitingSampleCollection => 'Esperando toma / resultado',
            InvoiceRequestWorkflowStatus::SubmittedToBilling => 'Enviada a facturación',
            InvoiceRequestWorkflowStatus::Cancelled => 'Cancelada',
            default => null,
        };
    }

    private function activationTriggerLabel(?string $activatedBy): ?string
    {
        return match ($activatedBy) {
            InvoiceRequestStatusLogTrigger::SampleWebhook->value => 'Activada al completar toma',
            InvoiceRequestStatusLogTrigger::ResultAvailable->value => 'Activada por resultado disponible',
            InvoiceRequestStatusLogTrigger::AdminManual->value => 'Activación manual',
            InvoiceRequestStatusLogTrigger::Reconciliation->value => 'Activación por reconciliación',
            default => null,
        };
    }

    private function statusLogTriggerLabel(?InvoiceRequestStatusLogTrigger $trigger): ?string
    {
        return match ($trigger) {
            InvoiceRequestStatusLogTrigger::Created => 'Paciente',
            InvoiceRequestStatusLogTrigger::SampleWebhook => 'Webhook laboratorio',
            InvoiceRequestStatusLogTrigger::ResultAvailable => 'Resultado disponible',
            InvoiceRequestStatusLogTrigger::AdminManual => 'Administrador',
            InvoiceRequestStatusLogTrigger::Reconciliation => 'Reconciliación',
            default => $trigger?->value,
        };
    }
}
