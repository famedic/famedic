<?php

namespace App\Services\LaboratoryPreparation;

use App\Enums\GdaOrderStatus;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;

final class LaboratoryPreparationEligibility
{
    private const NEGATIVE_STATUSES = [
        'pending' => 'payment_pending',
        'processing' => 'payment_pending',
        'failed' => 'payment_failed',
        'declined' => 'payment_failed',
        'error' => 'payment_failed',
        'fulfillment_failed' => 'payment_failed',
        'refunded' => 'payment_refunded',
        'partially_refunded' => 'payment_refunded',
        'partial_refunded' => 'payment_refunded',
        'uncertain' => 'payment_evidence_unresolved',
        'unknown' => 'payment_evidence_unresolved',
    ];

    public function evaluate(LaboratoryPurchase $purchase): LaboratoryPreparationEligibilityResult
    {
        $purchase->loadMissing(['laboratoryPurchaseItems', 'transactions']);

        if ($purchase->laboratoryPurchaseItems->isEmpty()) {
            return LaboratoryPreparationEligibilityResult::deny('laboratory_items_missing');
        }

        $gdaStatus = $purchase->gda_status;
        $confirmedGdaStatus = enum_exists(GdaOrderStatus::class)
            ? GdaOrderStatus::Confirmed->value
            : 'confirmed';
        $gdaStatusValue = $gdaStatus instanceof \BackedEnum
            ? $gdaStatus->value
            : (is_string($gdaStatus) ? strtolower(trim($gdaStatus)) : null);

        if ($gdaStatusValue !== $confirmedGdaStatus) {
            return LaboratoryPreparationEligibilityResult::deny('gda_not_confirmed');
        }

        $gdaOrderId = trim((string) $purchase->gda_order_id);
        if ($gdaOrderId === '' || $gdaOrderId === '0' || (ctype_digit($gdaOrderId) && (int) $gdaOrderId === 0)) {
            return LaboratoryPreparationEligibilityResult::deny('gda_folio_invalid');
        }

        if ((int) $purchase->gda_consecutivo <= 0) {
            return LaboratoryPreparationEligibilityResult::deny('gda_consecutive_missing');
        }

        $transactions = $purchase->transactions
            ->reject(fn (Transaction $transaction): bool => $transaction->trashed())
            ->values();

        if ($transactions->count() !== 1) {
            return LaboratoryPreparationEligibilityResult::deny('payment_evidence_unresolved');
        }

        return $this->evaluatePayment($transactions->first());
    }

    private function evaluatePayment(Transaction $transaction): LaboratoryPreparationEligibilityResult
    {
        $method = strtolower(trim((string) $transaction->payment_method));
        $paymentStatus = $this->normalizeStatus($transaction->payment_status);
        $gatewayStatus = $this->normalizeStatus($transaction->gateway_status);

        foreach ([$paymentStatus, $gatewayStatus] as $status) {
            if ($status !== null && isset(self::NEGATIVE_STATUSES[$status])) {
                return LaboratoryPreparationEligibilityResult::deny(
                    self::NEGATIVE_STATUSES[$status],
                    $method !== '' ? $method : null,
                );
            }
        }

        $eligible = match ($method) {
            'paypal' => $paymentStatus === 'captured'
                && in_array($gatewayStatus, ['completed', 'captured'], true)
                && (filled($transaction->gateway_transaction_id) || filled($transaction->provider_transaction_id)),
            'efevoopay' => $gatewayStatus === 'completed'
                && filled($transaction->gateway_transaction_id),
            'odessa' => filled($transaction->reference_id)
                && strtolower(trim((string) $transaction->reference_id)) !== 'pending'
                && ($paymentStatus === null || in_array($paymentStatus, ['completed', 'paid', 'success', 'succeeded'], true))
                && ($gatewayStatus === null || in_array($gatewayStatus, ['completed', 'paid', 'success', 'succeeded'], true)),
            'coupon_balance' => (int) $transaction->transaction_amount_cents === 0
                && strtolower((string) $transaction->gateway) === 'coupon_balance'
                && $gatewayStatus === 'completed',
            default => false,
        };

        return $eligible
            ? LaboratoryPreparationEligibilityResult::allow($method)
            : LaboratoryPreparationEligibilityResult::deny('payment_evidence_unresolved', $method !== '' ? $method : null);
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        return strtolower(trim($status));
    }
}
