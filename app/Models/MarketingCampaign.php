<?php

namespace App\Models;

use App\Enums\MarketingCampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MarketingCampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function links(): HasMany
    {
        return $this->hasMany(MarketingCampaignLink::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(MarketingCampaignCollection::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Administrator::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Administrator::class, 'updated_by');
    }
}
