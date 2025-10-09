<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryConcierge extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Administrator::class);
    }
}
