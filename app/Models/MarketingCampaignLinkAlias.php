<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignLinkAlias extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function link(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'marketing_campaign_link_id');
    }
}
