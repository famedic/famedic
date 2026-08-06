<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignCollectionItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignCollection::class, 'marketing_campaign_collection_id');
    }

    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }
}
