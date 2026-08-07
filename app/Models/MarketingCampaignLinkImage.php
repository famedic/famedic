<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MarketingCampaignLinkImage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'marketing_campaign_link_id');
    }

    public function resolvedUrl(): ?string
    {
        if ($this->source === 'external' && filled($this->external_url)) {
            return $this->external_url;
        }

        if ($this->source === 'upload' && filled($this->path)) {
            $disk = $this->disk ?: config('filesystems.default', 'local');

            return Storage::disk($disk)->url($this->path);
        }

        return null;
    }
}
