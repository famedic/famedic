<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignLinkStatus;
use App\Models\Administrator;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkCategory;
use App\Models\MarketingCampaignLinkImage;
use App\Models\MarketingCampaignLinkProduct;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use Illuminate\Support\Facades\DB;

class DuplicateMarketingCampaignLinkAction
{
    public function __construct(
        private MarketingCampaignLinkSlugService $slugService,
    ) {}

    public function __invoke(
        MarketingCampaignLink $source,
        Administrator $administrator,
    ): MarketingCampaignLink {
        $source->load([
            'landingProducts',
            'landingCategories',
            'landingImages',
        ]);

        return DB::transaction(function () use ($source, $administrator) {
            $baseSlug = $this->slugService->normalize(
                'copia-'.$source->slug,
            );
            $slug = $this->slugService->assertAvailable($baseSlug);

            $duplicate = MarketingCampaignLink::query()->create([
                'marketing_campaign_id' => $source->marketing_campaign_id,
                'name' => 'Copia de '.$source->name,
                'slug' => $slug,
                'status' => MarketingCampaignLinkStatus::Draft,
                'target_type' => $source->target_type,
                'target_payload' => $source->target_payload,
                'public_title' => $source->public_title,
                'public_subtitle' => $source->public_subtitle,
                'public_description' => $source->public_description,
                'eyebrow' => $source->eyebrow,
                'hero_image_source' => $source->hero_image_source,
                'hero_image_disk' => $source->hero_image_disk,
                'hero_image_path' => $source->hero_image_path,
                'hero_image_url' => $source->hero_image_url,
                'hero_image_alt' => $source->hero_image_alt,
                'primary_cta_label' => $source->primary_cta_label,
                'secondary_cta_label' => $source->secondary_cta_label,
                'show_prices' => $source->show_prices,
                'show_brand_logo' => $source->show_brand_logo,
                'show_campaign_dates' => $source->show_campaign_dates,
                'landing_layout' => $source->landing_layout,
                'utm_source' => $source->utm_source,
                'utm_medium' => $source->utm_medium,
                'utm_campaign' => $source->utm_campaign,
                'utm_term' => $source->utm_term,
                'utm_content' => $source->utm_content,
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);

            foreach ($source->landingProducts as $item) {
                MarketingCampaignLinkProduct::query()->create([
                    'marketing_campaign_link_id' => $duplicate->id,
                    'laboratory_test_id' => $item->laboratory_test_id,
                    'section' => $item->section,
                    'position' => $item->position,
                    'is_featured' => $item->is_featured,
                ]);
            }

            foreach ($source->landingCategories as $item) {
                MarketingCampaignLinkCategory::query()->create([
                    'marketing_campaign_link_id' => $duplicate->id,
                    'laboratory_test_category_id' => $item->laboratory_test_category_id,
                    'position' => $item->position,
                ]);
            }

            foreach ($source->landingImages as $image) {
                MarketingCampaignLinkImage::query()->create([
                    'marketing_campaign_link_id' => $duplicate->id,
                    'type' => $image->type,
                    'source' => $image->source,
                    'disk' => $image->disk,
                    'path' => $image->path,
                    'external_url' => $image->external_url,
                    'alt_text' => $image->alt_text,
                    'position' => $image->position,
                ]);
            }

            return $duplicate->fresh();
        });
    }
}
