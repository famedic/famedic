<?php

namespace App\Services\Otp\Delivery;

use App\Models\OtpDeliveryOperation;
use App\Models\OtpSmsDeliveryReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class VonageSmsDeliveryReceiptProcessor
{
    public function __construct(
        private readonly VonageSmsDeliveryReceiptNormalizer $normalizer,
        private readonly VonageSmsDeliveryReceiptApplicator $applicator,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     * @return array{processed: bool, duplicate: bool, unknown_message: bool, pending_reconciliation: bool}
     */
    public function process(array $params): array
    {
        $normalized = $this->normalizer->normalize($params);
        if ($normalized['message_id'] === '') {
            return [
                'processed' => false,
                'duplicate' => false,
                'unknown_message' => true,
                'pending_reconciliation' => false,
            ];
        }

        $idempotencyKey = $this->normalizer->idempotencyKey(
            $normalized['message_id'],
            $normalized['raw_status'],
            $normalized['failure_code'],
            isset($params['scts']) ? (string) $params['scts'] : null,
        );

        $operation = OtpDeliveryOperation::query()
            ->where('provider_message_id', $normalized['message_id'])
            ->first();

        try {
            $receipt = OtpSmsDeliveryReceipt::query()->create([
                'provider_message_id' => $normalized['message_id'],
                'otp_delivery_operation_id' => $operation?->id,
                'receipt_status' => $normalized['status']->value,
                'raw_status' => $normalized['raw_status'],
                'status_rank' => $normalized['status']->rank(),
                'failure_code' => $normalized['failure_code'],
                'failure_reason' => $normalized['failure_reason'],
                'idempotency_key' => $idempotencyKey,
                'received_at' => $normalized['received_at'],
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return [
                'processed' => true,
                'duplicate' => true,
                'unknown_message' => $operation === null,
                'pending_reconciliation' => $operation === null,
            ];
        } catch (Throwable $e) {
            Log::warning('vonage_sms_dlr_persist_failed', [
                'message_id_prefix' => substr($normalized['message_id'], 0, 8),
                'error' => $e->getMessage(),
            ]);

            return [
                'processed' => false,
                'duplicate' => false,
                'unknown_message' => $operation === null,
                'pending_reconciliation' => false,
            ];
        }

        if ($operation === null) {
            Log::info('vonage_sms_dlr_pending_reconciliation', [
                'message_id_prefix' => substr($normalized['message_id'], 0, 8),
                'status' => $normalized['raw_status'],
            ]);

            return [
                'processed' => true,
                'duplicate' => false,
                'unknown_message' => true,
                'pending_reconciliation' => true,
            ];
        }

        $normalized['receipt_id'] = $receipt->id;
        $this->applicator->apply($receipt, $operation, $normalized);

        return [
            'processed' => true,
            'duplicate' => false,
            'unknown_message' => false,
            'pending_reconciliation' => false,
        ];
    }
}
