<?php

namespace App\Models;

use App\Enums\MarketingCampaignLinkProductSection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    public function resolvedImageUrl(): ?string
    {
        if ($this->image_source !== 'upload' || ! filled($this->image_path)) {
            return null;
        }

        $disk = $this->image_disk ?: 'public';

        try {
            return Storage::disk($disk)->url($this->image_path);
        } catch (\Throwable) {
            return null;
        }
    }
}
