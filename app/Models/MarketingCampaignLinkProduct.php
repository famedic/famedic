<?php

namespace App\Models;

use App\Enums\MarketingCampaignLinkProductSection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignLinkProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'section' => MarketingCampaignLinkProductSection::class,
            'is_featured' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'marketing_campaign_link_id');
    }

    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }
}
