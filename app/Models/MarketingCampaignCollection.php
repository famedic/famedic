<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaignCollection extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'laboratory_brand' => LaboratoryBrand::class,
            'is_active' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketingCampaignCollectionItem::class);
    }

    public function orderedItems(): HasMany
    {
        return $this->items()->orderBy('position')->orderBy('id');
    }

    public function laboratoryTests(): BelongsToMany
    {
        return $this->belongsToMany(
            LaboratoryTest::class,
            'marketing_campaign_collection_items'
        )->withPivot('position')->withTimestamps()->orderByPivot('position');
    }
}
