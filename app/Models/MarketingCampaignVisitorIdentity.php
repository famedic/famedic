<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignVisitorIdentity extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function attributions(): HasMany
    {
        return $this->hasMany(MarketingCampaignAttribution::class, 'marketing_campaign_visitor_identity_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MarketingCampaignVisit::class, 'marketing_campaign_visitor_identity_id');
    }
}
