<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignAttribution extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_touched_at' => 'datetime',
            'last_touched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function firstVisit(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisit::class, 'first_visit_id');
    }

    public function lastVisit(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisit::class, 'last_visit_id');
    }

    public function firstCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'first_campaign_id');
    }

    public function lastCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'last_campaign_id');
    }

    public function firstLink(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'first_link_id');
    }

    public function lastLink(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'last_link_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MarketingCampaignVisit::class, 'marketing_campaign_attribution_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActiveAt(\DateTimeInterface $at): bool
    {
        return $this->expires_at !== null && $this->expires_at->gt($at);
    }
}
