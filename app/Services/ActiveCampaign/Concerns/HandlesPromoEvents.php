<?php

namespace App\Services\ActiveCampaign\Concerns;

trait HandlesPromoEvents
{
    public function handlePromoValidated(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'promo.validated');
        $this->removeCouponTagByKey($contactId, 'promo.abandoned');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'fm_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'fm_promo_ultimo_codigo' => $payload['code'] ?? null,
            'fm_promo_estado' => 'validada',
            'fm_credito_compra_minima' => $this->formatCentsFieldValue($payload['min_purchase_cents'] ?? null),
            'fm_credito_expira_at' => $this->formatDateFieldValue($payload['expires_at'] ?? null),
        ]);
    }

    public function handlePromoUsed(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->addCouponTagByKey($contactId, 'promo.used');
        $this->removeCouponTagByKey($contactId, 'promo.validated');
        $this->removeCouponTagByKey($contactId, 'promo.abandoned');

        $fieldUpdates = [
            'fm_user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'fm_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'fm_promo_ultimo_codigo' => $payload['code'] ?? null,
            'fm_promo_estado' => 'usada',
        ];

        if (($payload['purchase_type'] ?? null) === 'lab') {
            $fieldUpdates['fm_ultima_compra_lab_at'] = $this->formatDateTimeFieldValue(
                $payload['confirmed_at'] ?? null
            );
        }

        $this->applyCouponFieldUpdates($contactId, $fieldUpdates);
    }

    public function handlePromoReleased(array $payload): void
    {
        $contactId = $this->ensureContactIdForCouponPayload($payload);

        $this->removeCouponTagByKey($contactId, 'promo.validated');

        $this->applyCouponFieldUpdates($contactId, [
            'fm_user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'fm_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'fm_promo_ultimo_codigo' => $payload['code'] ?? null,
            'fm_promo_estado' => 'liberada',
        ]);
    }
}
