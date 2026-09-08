<?php

namespace App\Enums\Otp;

enum OtpMovementStatus: string
{
    case InProgress = 'in_progress';
    case Sent = 'sent';
    case Verified = 'verified';
    case Replay = 'replay';
    case Decoy = 'decoy';
    case Expired = 'expired';
    case Blocked = 'blocked';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'En proceso',
            self::Sent => 'Enviado / aceptado por proveedor',
            self::Verified => 'Verificado',
            self::Replay => 'Replay',
            self::Decoy => 'Decoy / no elegible',
            self::Expired => 'Expirado',
            self::Blocked => 'Bloqueado',
            self::Failed => 'Fallido',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::InProgress => 'sky',
            self::Sent => 'lime',
            self::Verified => 'emerald',
            self::Replay => 'amber',
            self::Decoy => 'zinc',
            self::Expired => 'orange',
            self::Blocked => 'rose',
            self::Failed => 'red',
        };
    }
}
