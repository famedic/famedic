<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenavidesBenefitPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'promotion_modal_dismissed_at' => 'datetime',
            'promotion_modal_clicked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
