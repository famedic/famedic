<?php

namespace App\Models;

use App\Enums\LaboratoryAppointmentInteractionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryAppointmentInteraction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LaboratoryAppointmentInteractionType::class,
            'metadata' => 'array',
        ];
    }

    public function laboratoryAppointment(): BelongsTo
    {
        return $this->belongsTo(LaboratoryAppointment::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
