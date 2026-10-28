<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaboratoryPurchaseConfirmation extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'confirmed_at' => 'datetime', 'deadline_at' => 'datetime',
            'lease_until' => 'datetime', 'next_attempt_at' => 'datetime', 'accepted_at' => 'datetime',
            'attempts' => 'integer', 'preparation_attempts' => 'integer',
        ];
    }
}
