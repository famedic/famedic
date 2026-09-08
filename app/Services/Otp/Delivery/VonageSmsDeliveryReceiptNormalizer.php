<?php

namespace App\Services\Otp\Delivery;

use App\Enums\Otp\VonageSmsDeliveryStatus;

final class VonageSmsDeliveryReceiptNormalizer
{
    /**
     * @param  array<string, scalar|null>  $params
     * @return array{
     *     message_id: string,
     *     raw_status: string,
     *     status: VonageSmsDeliveryStatus,
     *     failure_code: ?string,
     *     failure_reason: ?string,
     *     received_at: \Illuminate\Support\Carbon
     * }
     */
    public function normalize(array $params): array
    {
        $messageId = (string) ($params['messageId'] ?? $params['message-id'] ?? '');
        $rawStatus = strtolower(trim((string) ($params['status'] ?? 'unknown')));
        $status = VonageSmsDeliveryStatus::fromVonageRaw($rawStatus);
        $errCode = isset($params['err-code']) ? (string) $params['err-code'] : null;

        return [
            'message_id' => $messageId,
            'raw_status' => $rawStatus !== '' ? $rawStatus : 'unknown',
            'status' => $status,
            'failure_code' => $this->normalizeFailureCode($errCode, $status),
            'failure_reason' => $this->normalizeFailureReason($errCode, $status),
            'received_at' => now(),
        ];
    }

    public function idempotencyKey(string $messageId, string $rawStatus, ?string $failureCode, ?string $scts): string
    {
        return hash('sha256', implode('|', [
            $messageId,
            $rawStatus,
            $failureCode ?? '',
            $scts ?? '',
        ]));
    }

    private function normalizeFailureCode(?string $errCode, VonageSmsDeliveryStatus $status): ?string
    {
        if ($status === VonageSmsDeliveryStatus::Delivered) {
            return $errCode === '0' || $errCode === null ? null : $errCode;
        }

        if ($errCode === null || $errCode === '') {
            return $status === VonageSmsDeliveryStatus::Unknown ? null : $status->value;
        }

        return $errCode;
    }

    private function normalizeFailureReason(?string $errCode, VonageSmsDeliveryStatus $status): ?string
    {
        if ($status === VonageSmsDeliveryStatus::Delivered) {
            return null;
        }

        return match ($errCode) {
            '0' => null,
            '1' => 'Error desconocido del operador',
            '2' => 'Teléfono apagado o fuera de cobertura',
            '3' => 'Teléfono no disponible',
            '4' => 'Bloqueado por operador',
            '5' => 'SMS portabilidad / routing',
            '6' => 'Número no soportado',
            '7' => 'Número bloqueado',
            '8' => 'Operador no alcanzable',
            '9' => 'Rechazado por operador',
            '10' => 'Mensaje inválido',
            default => match ($status) {
                VonageSmsDeliveryStatus::Expired => 'SMS expirado antes de entrega al dispositivo',
                VonageSmsDeliveryStatus::Rejected => 'SMS rechazado por Vonage u operador',
                VonageSmsDeliveryStatus::Failed => 'Entrega fallida reportada por operador',
                VonageSmsDeliveryStatus::Buffered => 'Mensaje en cola del operador',
                VonageSmsDeliveryStatus::Accepted => 'Aceptado por Vonage; entrega al dispositivo pendiente',
                default => $status === VonageSmsDeliveryStatus::Unknown
                    ? 'Estado de entrega desconocido'
                    : $status->label(),
            },
        };
    }
}
