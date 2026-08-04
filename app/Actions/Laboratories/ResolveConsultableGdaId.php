<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaConsultIdNotResolvableException;

class ResolveConsultableGdaId
{
    public const PATTERN = '/^[A-Z0-9]{3}L[0-9]{6}$/';

    /**
     * @return array{id: string, source: string}
     */
    public function __invoke(?string $orderId, array $payload): array
    {
        $candidates = [
            ['value' => $orderId, 'source' => 'gda_order_id'],
            ['value' => data_get($payload, 'id'), 'source' => 'payload.id'],
            ['value' => data_get($payload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta'), 'source' => 'infogda_etiqueta'],
            ['value' => data_get($payload, 'requisition.value'), 'source' => 'requisition.value'],
        ];

        foreach ($candidates as $candidate) {
            $value = $candidate['value'];

            if ($value === null || $value === '') {
                continue;
            }

            $normalized = (string) $value;

            if ($this->isConsultable($normalized)) {
                return [
                    'id' => $normalized,
                    'source' => $candidate['source'],
                ];
            }
        }

        throw new GdaConsultIdNotResolvableException(
            orderId: $orderId,
            payloadId: data_get($payload, 'id'),
            requisitionValue: data_get($payload, 'requisition.value'),
        );
    }

    public function isConsultable(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
