<?php

namespace App\Models;

use App\Enums\MarketingCampaignHeroImageSource;
use App\Enums\MarketingCampaignLinkProductSection;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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
            'show_prices' => 'boolean',
            'show_brand_logo' => 'boolean',
            'show_campaign_dates' => 'boolean',
            'hero_image_source' => MarketingCampaignHeroImageSource::class,
        ];
    }

    public function hasPublicContent(): bool
    {
        return filled($this->public_title)
            || filled($this->public_subtitle)
            || filled($this->public_description)
            || filled($this->eyebrow)
            || filled($this->hero_image_path)
            || filled($this->hero_image_url)
            || filled($this->primary_cta_label)
            || filled($this->secondary_cta_label);
    }

    public function resolvedHeroImageUrl(): ?string
    {
        $source = $this->hero_image_source ?? MarketingCampaignHeroImageSource::None;

        return match ($source) {
            MarketingCampaignHeroImageSource::External => filled($this->hero_image_url)
                ? $this->hero_image_url
                : null,
            MarketingCampaignHeroImageSource::Upload => $this->resolveUploadedHeroUrl(),
            default => null,
        };
    }

    private function resolveUploadedHeroUrl(): ?string
    {
        if (! filled($this->hero_image_path)) {
            return null;
        }

        $disk = $this->hero_image_disk ?: config('filesystems.default', 'local');

        try {
            return Storage::disk($disk)->url($this->hero_image_path);
        } catch (\Throwable) {
            $path = ltrim((string) $this->hero_image_path, '/');

            return str_starts_with($path, 'images/') ? '/'.$path : null;
        }
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MarketingCampaignLinkAlias::class);
    }

    public function landingProducts(): HasMany
    {
        return $this->hasMany(MarketingCampaignLinkProduct::class);
    }

    public function orderedLandingProducts(): HasMany
    {
        return $this->landingProducts()
            ->orderBy('section')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function primaryLandingProducts(): HasMany
    {
        return $this->landingProducts()
            ->where('section', MarketingCampaignLinkProductSection::Primary->value)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function relatedLandingProducts(): HasMany
    {
        return $this->landingProducts()
            ->where('section', MarketingCampaignLinkProductSection::Related->value)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function landingCategories(): HasMany
    {
        return $this->hasMany(MarketingCampaignLinkCategory::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function landingImages(): HasMany
    {
        return $this->hasMany(MarketingCampaignLinkImage::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MarketingCampaignVisit::class, 'marketing_campaign_link_id');
    }

    public function attributionsAsFirst(): HasMany
    {
        return $this->hasMany(MarketingCampaignAttribution::class, 'first_link_id');
    }

    public function attributionsAsLast(): HasMany
    {
        return $this->hasMany(MarketingCampaignAttribution::class, 'last_link_id');
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
