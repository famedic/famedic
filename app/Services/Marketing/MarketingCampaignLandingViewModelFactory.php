<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\Targets\MarketingCampaignResolvedTarget;

class MarketingCampaignLandingViewModelFactory
{
    public function __construct(
        private readonly MarketingCampaignLandingProductsResolver $productsResolver,
    ) {}

    /**
     * @param  array<string, string>  $allowedQuery
     * @return array<string, mixed>
     */
    public function make(
        MarketingCampaign $campaign,
        MarketingCampaignLink $link,
        MarketingCampaignResolvedTarget $resolved,
        array $allowedQuery = [],
    ): array {
        $brand = $resolved->brand;
        $categoryName = $resolved->category['name'] ?? null;

        $title = $this->firstFilled([
            $link->public_title,
            $resolved->sourceTitle,
            $campaign->name,
        ]);

        $subtitle = $this->firstFilled([
            $link->public_subtitle,
            $this->defaultSubtitle($brand['label'] ?? null, $categoryName),
        ]);

        $description = $this->firstFilled([
            $link->public_description,
            $resolved->sourceDescription,
            $campaign->description,
        ]);

        $eyebrow = $this->firstFilled([
            $link->eyebrow,
            'Campaña Famedic',
        ]);

        $primaryLabel = $this->firstFilled([
            $link->primary_cta_label,
            $this->defaultPrimaryCtaLabel($resolved->type, $brand['label'] ?? null, $categoryName),
        ]) ?? 'Ver estudios disponibles';

        $secondaryLabel = $this->firstFilled([
            $link->secondary_cta_label,
            'Ver todos los estudios',
        ]);

        $products = $this->productsResolver->resolve($link, $resolved, $allowedQuery);

        $brandEnum = is_string($brand['value'] ?? null) ? LaboratoryBrand::tryFrom($brand['value']) : null;

        $isAuthenticated = auth()->check();
        $loginUrl = route('campaign-links.require-auth', ['slug' => $link->slug, ...$allowedQuery]);

        return [
            'campaign' => [
                'name' => $campaign->name,
                'starts_at' => $campaign->starts_at,
                'ends_at' => $campaign->ends_at,
            ],
            'link' => [
                'slug' => $link->slug,
                'target_type' => $resolved->type->value,
            ],
            'content' => [
                'eyebrow' => $eyebrow,
                'title' => $title,
                'subtitle' => $subtitle,
                'description' => $description,
                'hero_image' => $link->resolvedHeroImageUrl(),
                'hero_image_alt' => $link->hero_image_alt,
                'gallery' => $this->galleryImages($link),
                'show_prices' => (bool) ($link->show_prices ?? true),
                'show_brand_logo' => (bool) ($link->show_brand_logo ?? true),
                'show_campaign_dates' => (bool) ($link->show_campaign_dates ?? false),
                'landing_layout' => $link->landing_layout ?: 'default',
            ],
            'brand' => $brand,
            'category' => $resolved->category,
            'products' => $products['primary'],
            'related_products' => $products['related'],
            'related_categories' => $this->relatedCategories($link, $brandEnum, $allowedQuery),
            'stores_url' => $brandEnum
                ? route('laboratory-stores.index', ['brand' => $brandEnum->value])
                : route('laboratory-stores.index'),
            'primary_action' => [
                'label' => $primaryLabel,
                'url' => $resolved->primaryDestinationUrl,
            ],
            'secondary_action' => $resolved->secondaryDestinationUrl
                ? [
                    'label' => $secondaryLabel,
                    'url' => $resolved->secondaryDestinationUrl,
                ]
                : null,
            'cart' => [
                'add_url' => route('laboratory-cart-items.store'),
                'requires_auth' => ! $isAuthenticated,
                'login_url' => $loginUrl,
            ],
            'can_add_to_cart' => $brandEnum !== null,
            'empty_message' => 'No hay estudios disponibles en esta campaña por el momento.',
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function relatedCategories(
        MarketingCampaignLink $link,
        ?LaboratoryBrand $brand,
        array $allowedQuery,
    ): array {
        if ($brand === null) {
            return [];
        }

        return $link->landingCategories()
            ->with('category:id,name')
            ->get()
            ->map(fn ($item) => $item->category)
            ->filter()
            ->map(fn ($category) => [
                'name' => $category->name,
                'url' => route('laboratory-tests', [
                    'laboratory_brand' => $brand->value,
                    'category' => $category->name,
                    ...$allowedQuery,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{url: string, alt: ?string}>
     */
    private function galleryImages(MarketingCampaignLink $link): array
    {
        return $link->landingImages()
            ->where('type', 'gallery')
            ->get()
            ->map(fn ($image) => [
                'url' => $image->resolvedUrl(),
                'alt' => $image->alt_text,
            ])
            ->filter(fn (array $image) => filled($image['url']))
            ->values()
            ->all();
    }

    /**
     * @param  list<?string>  $candidates
     */
    private function firstFilled(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function defaultSubtitle(?string $brandLabel, ?string $categoryName): ?string
    {
        if ($brandLabel && $categoryName) {
            return $brandLabel.' · '.$categoryName;
        }

        return $brandLabel;
    }

    private function defaultPrimaryCtaLabel(
        MarketingCampaignTargetType $type,
        ?string $brandLabel,
        ?string $categoryName,
    ): string {
        return match ($type) {
            MarketingCampaignTargetType::Brand => 'Ver todos los estudios',
            MarketingCampaignTargetType::Category => 'Ver estudios de esta categoría',
            MarketingCampaignTargetType::Product => 'Ver estudio',
            MarketingCampaignTargetType::Collection => 'Explorar estudios disponibles',
        };
    }
}
