<?php

namespace App\Services\ActiveCampaign\Concerns;

trait HandlesBeneficiaryEvents
{
    public function handlePendingBeneficiaryCreated(array $payload): void
    {
        $contactId = $this->ensureContactIdForBeneficiaryPayload($payload);

        $this->addCouponTagByKey($contactId, 'beneficiary.pending');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_credito_estado' => 'pendiente_registro',
            'fm_credito_monto' => $this->formatCentsFieldValue($payload['amount_cents'] ?? null),
            'fm_credito_expira_at' => $this->formatDateFieldValue($payload['expires_at'] ?? null),
            'fm_credito_compra_minima' => $this->formatCentsFieldValue($payload['min_purchase_cents'] ?? null),
            'fm_credito_campania' => $payload['campaign_name'] ?? null,
            'fm_credito_tipo' => $payload['coupon_type'] ?? null,
        ]);
    }

    public function handlePendingBeneficiaryActivated(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->removeCouponTagByKey($contactId, 'beneficiary.pending');
        $this->addCouponTagByKey($contactId, 'benefit.activated');
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

    public function handlePendingBeneficiaryCancelled(array $payload): void
    {
        $contactId = $this->ensureContactIdForBeneficiaryPayload($payload);

        $this->removeCouponTagByKey($contactId, 'beneficiary.pending');
        $this->addCouponTagByKey($contactId, 'credit.closed');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_credito_estado' => 'cancelado',
            'fm_credito_campania' => $payload['campaign_name'] ?? null,
        ]);
    }
}
