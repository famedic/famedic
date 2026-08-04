<?php

namespace App\Services\ActiveCampaign\Concerns;

trait HandlesCouponCreditEvents
{
    public function handleCouponCreditAssigned(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'credit.available');
        $this->removeCouponTagByKey($contactId, 'credit.closed');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'fm_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'fm_credito_estado' => 'disponible',
            'fm_credito_monto' => $this->formatCentsFieldValue($payload['amount_cents'] ?? null),
            'fm_credito_restante' => $this->formatCentsFieldValue($payload['remaining_cents'] ?? null),
            'fm_credito_expira_at' => $this->formatDateFieldValue($payload['expires_at'] ?? null),
            'fm_credito_compra_minima' => $this->formatCentsFieldValue($payload['min_purchase_cents'] ?? null),
            'fm_credito_campania' => $payload['campaign_name'] ?? null,
            'fm_credito_tipo' => $payload['coupon_type'] ?? null,
            'fm_saldo_total' => $this->formatCentsFieldValue($payload['saldo_total_cents'] ?? null),
            'fm_saldo_aplicable' => $this->formatCentsFieldValue($payload['saldo_aplicable_cents'] ?? null),
            'fm_saldo_condicionado' => $this->formatCentsFieldValue($payload['saldo_condicionado_cents'] ?? null),
        ]);
    }

    public function handleCouponCreditRedeemed(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'credit.used');
        $this->addCouponTagByKey($contactId, 'credit.closed');
        $this->removeCouponTagByKey($contactId, 'credit.available');
        $this->removeCouponTagByKey($contactId, 'credit.expiring');

        $redeemedAt = $payload['redeemed_at'] ?? null;
        $purchaseType = $payload['purchase_type'] ?? null;

        $fieldUpdates = [
            'fm_credito_estado' => 'usado',
            'fm_credito_restante' => $this->formatCentsFieldValue($payload['remaining_cents_after'] ?? 0),
            'fm_credito_ultimo_uso_at' => $this->formatDateTimeFieldValue($redeemedAt),
        ];

        if ($purchaseType === 'lab') {
            $fieldUpdates['fm_ultima_compra_lab_at'] = $this->formatDateTimeFieldValue($redeemedAt);
        }

        $this->applyCouponFieldUpdates($contactId, $fieldUpdates);
    }

    public function handleCouponCreditRestored(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $isUsable = (bool) ($payload['is_usable_after_restore'] ?? false);

        $this->addCouponTagByKey($contactId, 'credit.restored');

        if ($isUsable) {
            $this->addCouponTagByKey($contactId, 'credit.available');
            $this->removeCouponTagByKey($contactId, 'credit.closed');
            $estado = 'disponible';
        } else {
            $this->removeCouponTagByKey($contactId, 'credit.available');
            $this->addCouponTagByKey($contactId, 'credit.closed');
            $estado = 'cerrado';
        }

        $this->applyCouponFieldUpdates($contactId, [
            'fm_credito_estado' => $estado,
            'fm_credito_restante' => $this->formatCentsFieldValue($payload['remaining_cents_after'] ?? null),
            'fm_credito_expira_at' => $this->formatDateFieldValue($payload['expires_at'] ?? null),
            'fm_saldo_total' => $this->formatCentsFieldValue($payload['saldo_total_cents'] ?? null),
            'fm_saldo_aplicable' => $this->formatCentsFieldValue($payload['saldo_aplicable_cents'] ?? null),
            'fm_saldo_condicionado' => $this->formatCentsFieldValue($payload['saldo_condicionado_cents'] ?? null),
        ]);
    }

    public function handleCouponCreditRevoked(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'credit.revoked');
        $this->addCouponTagByKey($contactId, 'credit.closed');
        $this->removeCouponTagByKey($contactId, 'credit.available');
        $this->removeCouponTagByKey($contactId, 'credit.expiring');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_credito_estado' => 'revocado',
            'fm_credito_restante' => $this->formatCentsFieldValue(0),
            'fm_saldo_total' => $this->formatCentsFieldValue($payload['saldo_total_cents'] ?? null),
            'fm_saldo_aplicable' => $this->formatCentsFieldValue($payload['saldo_aplicable_cents'] ?? null),
            'fm_saldo_condicionado' => $this->formatCentsFieldValue($payload['saldo_condicionado_cents'] ?? null),
        ]);
    }

    public function handleCouponCreditExpiring(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'credit.expiring');
        $this->addCouponTagByKey($contactId, 'credit.available');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'fm_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'fm_credito_estado' => 'por_vencer',
            'fm_credito_restante' => $this->formatCentsFieldValue($payload['remaining_cents'] ?? null),
            'fm_credito_expira_at' => $this->formatDateFieldValue($payload['expires_at'] ?? null),
            'fm_credito_compra_minima' => $this->formatCentsFieldValue($payload['min_purchase_cents'] ?? null),
            'fm_credito_campania' => $payload['campaign_name'] ?? null,
            'fm_credito_tipo' => $payload['coupon_type'] ?? null,
            'fm_saldo_total' => $this->formatCentsFieldValue($payload['saldo_total_cents'] ?? null),
            'fm_saldo_aplicable' => $this->formatCentsFieldValue($payload['saldo_aplicable_cents'] ?? null),
            'fm_saldo_condicionado' => $this->formatCentsFieldValue($payload['saldo_condicionado_cents'] ?? null),
        ]);
    }
}
