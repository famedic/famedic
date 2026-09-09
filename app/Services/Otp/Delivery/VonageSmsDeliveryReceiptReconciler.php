<?php

namespace App\Services\Otp\Delivery;

use App\Models\OtpDeliveryOperation;
use App\Models\OtpSmsDeliveryReceipt;
use App\Models\OtpSmsDeliveryReceiptApplication;
use Illuminate\Support\Collection;

/**
 * Reconciles DLR callbacks that arrived before provider_message_id was persisted.
 */
class VonageSmsDeliveryReceiptReconciler
{
    public function __construct(
        private readonly VonageSmsDeliveryReceiptNormalizer $normalizer,
        private readonly VonageSmsDeliveryReceiptApplicator $applicator,
    ) {}

    public function reconcilePendingForMessageId(string $messageId, OtpDeliveryOperation $operation): int
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return 0;
        }

        $applied = 0;

        foreach ($this->pendingReceiptsForMessageId($messageId) as $receipt) {
            $normalized = $this->normalizedFromReceipt($receipt);
            if ($this->applicator->apply($receipt, $operation, $normalized)) {
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * @return Collection<int, OtpSmsDeliveryReceipt>
     */
    private function pendingReceiptsForMessageId(string $messageId): Collection
    {
        $appliedReceiptIds = OtpSmsDeliveryReceiptApplication::query()
            ->select('otp_sms_delivery_receipt_id');

        return OtpSmsDeliveryReceipt::query()
            ->where('provider_message_id', $messageId)
            ->whereNotIn('id', $appliedReceiptIds)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedFromReceipt(OtpSmsDeliveryReceipt $receipt): array
    {
        $status = \App\Enums\Otp\VonageSmsDeliveryStatus::tryFrom($receipt->receipt_status)
            ?? \App\Enums\Otp\VonageSmsDeliveryStatus::Unknown;

        return [
            'message_id' => $receipt->provider_message_id,
            'raw_status' => $receipt->raw_status ?? $receipt->receipt_status,
            'status' => $status,
            'failure_code' => $receipt->failure_code,
            'failure_reason' => $receipt->failure_reason,
            'received_at' => $receipt->received_at ?? now(),
            'receipt_id' => $receipt->id,
        ];
    }
}
