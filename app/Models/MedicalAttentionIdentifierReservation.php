<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalAttentionIdentifierReservation extends Model
{
    public const STATUS_RESERVED = 'RESERVED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
        ];
    }
}
