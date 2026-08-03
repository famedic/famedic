<?php

namespace App\Enums;

enum LaboratoryBillingStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InProgress => 'En proceso',
            self::Completed => 'Completada',
            self::Overdue => 'Atrasada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::InProgress => 'sky',
            self::Completed => 'lime',
            self::Overdue => 'red',
        };
    }
}
