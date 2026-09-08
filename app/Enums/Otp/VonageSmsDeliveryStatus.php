<?php

namespace App\Enums\Otp;

enum VonageSmsDeliveryStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Buffered = 'buffered';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Enviado a Vonage',
            self::Accepted => 'Aceptado por Vonage',
            self::Delivered => 'Entregado (operador)',
            self::Buffered => 'En cola (buffered)',
            self::Rejected => 'Rechazado',
            self::Failed => 'Fallido',
            self::Expired => 'Expirado (SMS)',
            self::Unknown => 'Desconocido',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Delivered => 'lime',
            self::Accepted, self::Submitted, self::Buffered => 'sky',
            self::Rejected, self::Failed, self::Expired => 'red',
            self::Unknown => 'zinc',
        };
    }

    /** Higher rank wins when callbacks arrive out of order. */
    public function rank(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Submitted => 10,
            self::Accepted => 20,
            self::Buffered => 30,
            self::Rejected, self::Failed, self::Expired => 80,
            self::Delivered => 100,
        };
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this, [self::Rejected, self::Failed, self::Expired], true);
    }

    public function isDelivered(): bool
    {
        return $this === self::Delivered;
    }

    public static function fromVonageRaw(?string $raw): self
    {
        $normalized = strtolower(trim((string) $raw));

        return match ($normalized) {
            'accepted' => self::Accepted,
            'delivered' => self::Delivered,
            'buffered' => self::Buffered,
            'rejected' => self::Rejected,
            'failed' => self::Failed,
            'expired' => self::Expired,
            'submitted' => self::Submitted,
            default => self::Unknown,
        };
    }
}
