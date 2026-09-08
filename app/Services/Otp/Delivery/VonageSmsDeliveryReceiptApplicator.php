<?php

namespace App\Services\Otp\Delivery;

use App\Enums\Otp\OtpMovementFlow;
use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;
use App\Enums\Otp\VonageSmsDeliveryStatus;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpSmsDeliveryReceipt;
use App\Models\OtpSmsDeliveryReceiptApplication;
use App\Services\Otp\Monitoring\OtpMovementRecorder;
use Illuminate\Database\UniqueConstraintViolationException;

final class VonageSmsDeliveryReceiptApplicator
{
    public function __construct(private readonly OtpMovementRecorder $movementRecorder) {}

    /**
     * Idempotently applies a stored receipt to a delivery operation.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function apply(
        OtpSmsDeliveryReceipt $receipt,
        OtpDeliveryOperation $operation,
        array $normalized,
    ): bool {
        if ($this->wasAlreadyApplied($receipt->id)) {
            return false;
        }

        try {
            OtpSmsDeliveryReceiptApplication::query()->create([
                'otp_sms_delivery_receipt_id' => $receipt->id,
                'otp_delivery_operation_id' => $operation->id,
                'applied_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $this->maybeAdvanceOperationStatus($operation, $normalized['status'], $normalized);
        $this->recordMovementEvent($operation, $normalized);

        return true;
    }

    private function wasAlreadyApplied(int $receiptId): bool
    {
        return OtpSmsDeliveryReceiptApplication::query()
            ->where('otp_sms_delivery_receipt_id', $receiptId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function maybeAdvanceOperationStatus(
        OtpDeliveryOperation $operation,
        VonageSmsDeliveryStatus $incoming,
        array $normalized,
    ): void {
        $current = is_string($operation->sms_delivery_status)
            ? VonageSmsDeliveryStatus::tryFrom($operation->sms_delivery_status)
            : null;

        $currentRank = $current?->rank() ?? 0;
        if ($incoming->rank() < $currentRank) {
            return;
        }

        $operation->update([
            'sms_delivery_status' => $incoming->value,
            'sms_delivery_status_at' => $normalized['received_at'],
            'sms_failure_code' => $normalized['failure_code'],
            'sms_failure_reason' => $normalized['failure_reason'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function recordMovementEvent(OtpDeliveryOperation $operation, array $normalized): void
    {
        /** @var VonageSmsDeliveryStatus $status */
        $status = $normalized['status'];
        $flow = OtpMovementFlow::fromPurpose($operation->purpose);
        if ($flow === null) {
            return;
        }

        [$stage, $movementStatus] = match ($status) {
            VonageSmsDeliveryStatus::Delivered => [OtpMovementStage::SmsOperatorDelivered, OtpMovementStatus::Sent],
            VonageSmsDeliveryStatus::Accepted, VonageSmsDeliveryStatus::Submitted => [OtpMovementStage::SmsOperatorAccepted, OtpMovementStatus::InProgress],
            VonageSmsDeliveryStatus::Buffered => [OtpMovementStage::SmsOperatorBuffered, OtpMovementStatus::InProgress],
            VonageSmsDeliveryStatus::Expired => [OtpMovementStage::SmsOperatorExpired, OtpMovementStatus::Failed],
            VonageSmsDeliveryStatus::Rejected => [OtpMovementStage::SmsOperatorRejected, OtpMovementStatus::Failed],
            VonageSmsDeliveryStatus::Failed => [OtpMovementStage::SmsOperatorFailed, OtpMovementStatus::Failed],
            default => [OtpMovementStage::SmsOperatorUnknown, OtpMovementStatus::Failed],
        };

        $operation->loadMissing('challenge');

        $this->movementRecorder->record([
            'flow' => $flow->value,
            'operation' => 'sms_dlr',
            'stage' => $stage->value,
            'status' => $movementStatus->value,
            'channel' => 'sms',
            'correlation_id' => $operation->correlation_id,
            'challenge_public_id' => $operation->challenge?->public_id,
            'provider_alias' => $operation->provider_alias ?? 'vonage',
            'provider_result_class' => $status->value,
            'otp_challenge_id' => $operation->otp_challenge_id,
            'otp_delivery_operation_id' => $operation->id,
            'technical_message' => $this->technicalMessage($status, $normalized),
            'meta' => [
                'provider_message_id' => $normalized['message_id'],
                'failure_code' => $normalized['failure_code'],
                'receipt_id' => $normalized['receipt_id'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function technicalMessage(VonageSmsDeliveryStatus $status, array $normalized): string
    {
        return match ($status) {
            VonageSmsDeliveryStatus::Delivered => 'Operador reportó entrega al dispositivo.',
            VonageSmsDeliveryStatus::Accepted, VonageSmsDeliveryStatus::Submitted => 'Vonage aceptó el SMS; entrega al dispositivo pendiente.',
            VonageSmsDeliveryStatus::Buffered => 'SMS en cola del operador.',
            VonageSmsDeliveryStatus::Expired => 'SMS expirado en red del operador (no confundir con challenge OTP expirado).',
            VonageSmsDeliveryStatus::Rejected => 'SMS rechazado: '.($normalized['failure_reason'] ?? 'motivo no especificado'),
            VonageSmsDeliveryStatus::Failed => 'Entrega SMS fallida: '.($normalized['failure_reason'] ?? 'motivo no especificado'),
            default => 'Estado de entrega SMS desconocido.',
        };
    }
}
