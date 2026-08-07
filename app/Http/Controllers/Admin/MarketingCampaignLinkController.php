<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignLinkAction;
use App\Actions\Admin\MarketingCampaigns\DuplicateMarketingCampaignLinkAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignLinkAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\StoreMarketingCampaignLinkRequest;
use App\Http\Requests\Admin\MarketingCampaigns\UpdateMarketingCampaignLinkRequest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketingCampaignLinkController extends Controller
{
    public function create(MarketingCampaign $marketingCampaign): Response
    {
        $this->authorize('create', MarketingCampaignLink::class);
        $this->authorize('view', $marketingCampaign);
        abort_if($marketingCampaign->isArchived(), 403);

        return Inertia::render('Admin/MarketingCampaigns/Links/Create', [
            'campaign' => [
                'id' => $marketingCampaign->id,
                'name' => $marketingCampaign->name,
            ],
            'wizardMode' => 'link',
            'utmPresets' => $this->utmPresets(),
            'promotionOptions' => $this->promotionOptions(),
            ...$this->formOptions($marketingCampaign),
        ]);
    }

    public function store(
        StoreMarketingCampaignLinkRequest $request,
        MarketingCampaign $marketingCampaign,
        CreateMarketingCampaignLinkAction $action,
    ): RedirectResponse {
        $this->authorize('view', $marketingCampaign);

        $action(
            array_merge(
                $request->safe()->except(['marketing_campaign_id', 'hero_image', 'gallery_uploads']),
                ['marketing_campaign_id' => $marketingCampaign->id],
                [
                    'gallery_items' => $request->input('gallery_items', []),
                    'gallery_uploads' => $request->file('gallery_uploads', []),
                ],
            ),
            $request->user()->administrator,
            $request->file('hero_image'),
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Enlace creado.');
    }

    public function edit(
        MarketingCampaign $marketingCampaign,
        MarketingCampaignLink $marketingCampaignLink,
    ): Response {
        $this->ensureLinkBelongsToCampaign($marketingCampaign, $marketingCampaignLink);
        $this->authorize('update', $marketingCampaignLink);

        $marketingCampaignLink->load([
            'aliases' => fn ($query) => $query->orderByDesc('created_at'),
            'primaryLandingProducts.laboratoryTest:id,name,other_name,brand,famedic_price_cents,laboratory_test_category_id',
            'primaryLandingProducts.laboratoryTest.laboratoryTestCategory:id,name',
            'relatedLandingProducts.laboratoryTest:id,name,other_name,brand,famedic_price_cents,laboratory_test_category_id',
            'relatedLandingProducts.laboratoryTest.laboratoryTestCategory:id,name',
            'landingCategories.category:id,name',
            'landingImages' => fn ($query) => $query->where('type', 'gallery')->orderBy('position')->orderBy('id'),
        ]);

        return Inertia::render('Admin/MarketingCampaigns/Links/Edit', [
            'campaign' => [
                'id' => $marketingCampaign->id,
                'name' => $marketingCampaign->name,
            ],
            'link' => [
                'id' => $marketingCampaignLink->id,
                'name' => $marketingCampaignLink->name,
                'slug' => $marketingCampaignLink->slug,
                'status' => $marketingCampaignLink->status?->value ?? $marketingCampaignLink->status,
                'target_type' => $marketingCampaignLink->target_type?->value ?? $marketingCampaignLink->target_type,
                'target_payload' => $marketingCampaignLink->target_payload ?? [],
                'public_title' => $marketingCampaignLink->public_title,
                'public_subtitle' => $marketingCampaignLink->public_subtitle,
                'public_description' => $marketingCampaignLink->public_description,
                'eyebrow' => $marketingCampaignLink->eyebrow,
                'primary_cta_label' => $marketingCampaignLink->primary_cta_label,
                'secondary_cta_label' => $marketingCampaignLink->secondary_cta_label,
                'show_prices' => (bool) $marketingCampaignLink->show_prices,
                'show_brand_logo' => (bool) $marketingCampaignLink->show_brand_logo,
                'show_campaign_dates' => (bool) $marketingCampaignLink->show_campaign_dates,
                'landing_layout' => $marketingCampaignLink->landing_layout ?: 'default',
                'hero_image_source' => $marketingCampaignLink->hero_image_source?->value
                    ?? $marketingCampaignLink->hero_image_source
                    ?? 'none',
                'hero_image_url' => $marketingCampaignLink->hero_image_url,
                'hero_image_alt' => $marketingCampaignLink->hero_image_alt,
                'hero_image_preview_url' => $marketingCampaignLink->resolvedHeroImageUrl(),
                'utm_source' => $marketingCampaignLink->utm_source,
                'utm_medium' => $marketingCampaignLink->utm_medium,
                'utm_campaign' => $marketingCampaignLink->utm_campaign,
                'utm_term' => $marketingCampaignLink->utm_term,
                'utm_content' => $marketingCampaignLink->utm_content,
                'starts_at' => $marketingCampaignLink->starts_at,
                'ends_at' => $marketingCampaignLink->ends_at,
                'aliases' => $marketingCampaignLink->aliases->map(fn ($alias) => [
                    'id' => $alias->id,
                    'slug' => $alias->slug,
                    'created_at' => $alias->created_at,
                ]),
                'primary_laboratory_test_ids' => $marketingCampaignLink->primaryLandingProducts
                    ->pluck('laboratory_test_id')
                    ->values(),
                'related_laboratory_test_ids' => $marketingCampaignLink->relatedLandingProducts
                    ->pluck('laboratory_test_id')
                    ->values(),
                'related_category_ids' => $marketingCampaignLink->landingCategories
                    ->pluck('laboratory_test_category_id')
                    ->values(),
                'primary_products' => $marketingCampaignLink->primaryLandingProducts
                    ->map(fn ($item) => $this->productLabel($item))
                    ->filter()
                    ->values(),
                'related_products' => $marketingCampaignLink->relatedLandingProducts
                    ->map(fn ($item) => $this->productLabel($item))
                    ->filter()
                    ->values(),
                'related_categories' => $marketingCampaignLink->landingCategories
                    ->map(fn ($item) => $item->category
                        ? ['id' => $item->category->id, 'name' => $item->category->name]
                        : null)
                    ->filter()
                    ->values(),
                'gallery_images' => $marketingCampaignLink->landingImages
                    ->map(fn ($image) => [
                        'id' => $image->id,
                        'url' => $image->resolvedUrl(),
                        'alt' => $image->alt_text,
                        'source' => $image->source,
                    ])
                    ->values(),
            ],
            ...$this->formOptions($marketingCampaign),
        ]);
    }

    public function update(
        UpdateMarketingCampaignLinkRequest $request,
        MarketingCampaign $marketingCampaign,
        MarketingCampaignLink $marketingCampaignLink,
        UpdateMarketingCampaignLinkAction $action,
    ): RedirectResponse {
        $this->ensureLinkBelongsToCampaign($marketingCampaign, $marketingCampaignLink);

        $action(
            $marketingCampaignLink,
            array_merge(
                $request->safe()->except(['hero_image', 'gallery_uploads']),
                [
                    'gallery_items' => $request->input('gallery_items', []),
                    'gallery_uploads' => $request->file('gallery_uploads', []),
                ],
            ),
            $request->user()->administrator,
            $request->file('hero_image'),
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Enlace actualizado.');
    }

    public function duplicate(
        MarketingCampaign $marketingCampaign,
        MarketingCampaignLink $marketingCampaignLink,
        DuplicateMarketingCampaignLinkAction $action,
    ): RedirectResponse {
        $this->ensureLinkBelongsToCampaign($marketingCampaign, $marketingCampaignLink);
        $this->authorize('update', $marketingCampaignLink);
        abort_if($marketingCampaign->isArchived(), 403);

        $duplicate = $action(
            $marketingCampaignLink,
            request()->user()->administrator,
        );

        return redirect()
            ->route('admin.marketing-campaigns.links.edit', [
                $marketingCampaign,
                $duplicate,
            ])
            ->flashMessage('Enlace duplicado como borrador.');
    }

    /**
     * @return array{id: int, name: string, other_name: ?string}|null
     */
    private function productLabel(mixed $item): ?array
    {
        $test = $item->laboratoryTest;

        if (! $test) {
            return null;
        }

        return [
            'id' => $test->id,
            'name' => $test->name,
            'other_name' => $test->other_name,
            'brand' => $test->brand?->value,
            'brand_label' => $test->brand?->label(),
            'category_name' => $test->laboratoryTestCategory?->name,
            'famedic_price_cents' => (int) $test->famedic_price_cents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(MarketingCampaign $campaign): array
    {
        return [
            'statusOptions' => collect(MarketingCampaignLinkStatus::cases())
                ->map(fn (MarketingCampaignLinkStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values()
                ->all(),
            'targetTypeOptions' => collect(MarketingCampaignTargetType::cases())
                ->map(fn (MarketingCampaignTargetType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->values()
                ->all(),
            'brands' => LaboratoryBrand::brandsData(),
            'categories' => LaboratoryTestCategory::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'collections' => $campaign->collections()
                ->whereNull('deleted_at')
                ->withCount('items')
                ->orderBy('name')
                ->get(['id', 'name', 'public_title', 'is_active', 'laboratory_brand'])
                ->map(function ($collection) {
                    $collection->load([
                        'orderedItems' => fn ($query) => $query
                            ->orderBy('position')
                            ->limit(5)
                            ->with('laboratoryTest:id,name,laboratory_test_category_id'),
                    ]);

                    return [
                        'id' => $collection->id,
                        'name' => $collection->name,
                        'public_title' => $collection->public_title,
                        'is_active' => $collection->is_active,
                        'laboratory_brand' => $collection->laboratory_brand?->value ?? $collection->laboratory_brand,
                        'laboratory_brand_label' => $collection->laboratory_brand?->label(),
                        'items_count' => (int) $collection->items_count,
                        'preview_items' => $collection->orderedItems
                            ->map(fn ($item) => [
                                'id' => $item->laboratoryTest?->id,
                                'name' => $item->laboratoryTest?->name,
                                'category_name' => $item->laboratoryTest?->laboratoryTestCategory?->name,
                            ])
                            ->filter(fn ($item) => $item['id'])
                            ->values()
                            ->all(),
                    ];
                }),
            'productSearchUrl' => route('admin.marketing-campaigns.product-search'),
            'maxCollectionItems' => 50,
        ];
    }

    /**
     * @return list<array{value: string, label: string, source: string, medium: string}>
     */
    private function utmPresets(): array
    {
        return [
            ['value' => 'facebook', 'label' => 'Facebook / Instagram pagado', 'source' => 'facebook', 'medium' => 'paid_social'],
            ['value' => 'google', 'label' => 'Google Ads', 'source' => 'google', 'medium' => 'cpc'],
            ['value' => 'whatsapp', 'label' => 'WhatsApp', 'source' => 'whatsapp', 'medium' => 'social'],
            ['value' => 'email', 'label' => 'Correo', 'source' => 'email', 'medium' => 'email'],
            ['value' => 'qr', 'label' => 'Código QR', 'source' => 'qr', 'medium' => 'offline'],
            ['value' => 'organic', 'label' => 'Orgánico', 'source' => 'organic', 'medium' => 'social'],
            ['value' => 'custom', 'label' => 'Personalizado', 'source' => '', 'medium' => ''],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function promotionOptions(): array
    {
        return [
            ['value' => 'brand', 'label' => 'Una marca', 'description' => 'Landing general de un laboratorio.', 'target_type' => 'brand'],
            ['value' => 'category', 'label' => 'Una categoría', 'description' => 'Estudios de una categoría específica.', 'target_type' => 'category'],
            ['value' => 'product', 'label' => 'Un producto', 'description' => 'Un estudio específico.', 'target_type' => 'product'],
            ['value' => 'multiple_products', 'label' => 'Varios productos', 'description' => 'Selección personalizada por marca.', 'target_type' => 'brand'],
            ['value' => 'existing_collection', 'label' => 'Colección existente', 'description' => 'Conjunto reutilizable de estudios.', 'target_type' => 'collection'],
            ['value' => 'new_collection', 'label' => 'Crear colección nueva', 'description' => 'Define un grupo nuevo dentro del wizard.', 'target_type' => 'collection'],
        ];
    }

    private function ensureLinkBelongsToCampaign(
        MarketingCampaign $campaign,
        MarketingCampaignLink $link,
    ): void {
        abort_unless(
            (int) $link->marketing_campaign_id === (int) $campaign->id,
            404,
        );
    }
}
