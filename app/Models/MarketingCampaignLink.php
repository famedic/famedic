<?php

namespace App\Models;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaignLink extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MarketingCampaignLinkStatus::class,
            'target_type' => MarketingCampaignTargetType::class,
            'target_payload' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MarketingCampaignLinkAlias::class);
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
