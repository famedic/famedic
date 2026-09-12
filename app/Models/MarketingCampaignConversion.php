<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignConversion extends Model
{
    public const TYPE_LABORATORY_PURCHASE = 'laboratory_purchase';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => false);
        static::deleting(static fn () => false);
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignAttribution::class, 'marketing_campaign_attribution_id');
    }

    public function visitorIdentity(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisitorIdentity::class, 'marketing_campaign_visitor_identity_id');
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class, 'purchase_id');
    }

    public function firstVisit(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisit::class, 'first_visit_id');
    }

    public function lastVisit(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisit::class, 'last_visit_id');
    }
}
