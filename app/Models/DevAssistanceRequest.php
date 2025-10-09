<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DevAssistanceRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected static $unguarded = true;

    protected $appends = [
        'formatted_requested_at',
        'formatted_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function devAssistanceRequestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Administrator::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DevAssistanceComment::class)->orderBy('created_at', 'asc');
    }

    protected function formattedRequestedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->requested_at)->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedResolvedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->resolved_at ? localizedDate($this->resolved_at)->isoFormat('D MMM Y h:mm a') : null
        );
    }
}
