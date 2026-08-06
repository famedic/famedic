<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignCollectionAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignCollectionAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\StoreMarketingCampaignCollectionRequest;
use App\Http\Requests\Admin\MarketingCampaigns\UpdateMarketingCampaignCollectionRequest;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketingCampaignCollectionController extends Controller
{
    public function create(MarketingCampaign $marketingCampaign): Response
    {
        $this->authorize('create', MarketingCampaignCollection::class);
        $this->authorize('view', $marketingCampaign);
        abort_if($marketingCampaign->isArchived(), 403);

        return Inertia::render('Admin/MarketingCampaigns/Collections/Create', [
            'campaign' => [
                'id' => $marketingCampaign->id,
                'name' => $marketingCampaign->name,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(
        StoreMarketingCampaignCollectionRequest $request,
        MarketingCampaign $marketingCampaign,
        CreateMarketingCampaignCollectionAction $action,
    ): RedirectResponse {
        $this->authorize('view', $marketingCampaign);

        $action(array_merge(
            $request->safe()->except(['marketing_campaign_id']),
            [
                'marketing_campaign_id' => $marketingCampaign->id,
                'laboratory_test_ids' => $request->input('laboratory_test_ids', []),
            ],
        ));

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Colección creada.');
    }

    public function edit(
        MarketingCampaign $marketingCampaign,
        MarketingCampaignCollection $marketingCampaignCollection,
    ): Response {
        $this->ensureCollectionBelongsToCampaign($marketingCampaign, $marketingCampaignCollection);
        $this->authorize('update', $marketingCampaignCollection);

        $marketingCampaignCollection->load(['orderedItems.laboratoryTest.laboratoryTestCategory']);

        return Inertia::render('Admin/MarketingCampaigns/Collections/Edit', [
            'campaign' => [
                'id' => $marketingCampaign->id,
                'name' => $marketingCampaign->name,
            ],
            'collection' => [
                'id' => $marketingCampaignCollection->id,
                'name' => $marketingCampaignCollection->name,
                'public_title' => $marketingCampaignCollection->public_title,
                'public_description' => $marketingCampaignCollection->public_description,
                'laboratory_brand' => $marketingCampaignCollection->laboratory_brand?->value
                    ?? $marketingCampaignCollection->laboratory_brand,
                'is_active' => $marketingCampaignCollection->is_active,
                'items' => $marketingCampaignCollection->orderedItems->map(function ($item) {
                    $test = $item->laboratoryTest;

                    return [
                        'id' => $test?->id,
                        'name' => $test?->name,
                        'other_name' => $test?->other_name,
                        'brand' => $test?->brand?->value ?? $test?->brand,
                        'brand_label' => $test?->brand?->label(),
                        'category' => $test?->laboratoryTestCategory?->name,
                        'famedic_price_cents' => $test?->famedic_price_cents,
                    ];
                })->filter(fn ($item) => $item['id'] !== null)->values(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(
        UpdateMarketingCampaignCollectionRequest $request,
        MarketingCampaign $marketingCampaign,
        MarketingCampaignCollection $marketingCampaignCollection,
        UpdateMarketingCampaignCollectionAction $action,
    ): RedirectResponse {
        $this->ensureCollectionBelongsToCampaign($marketingCampaign, $marketingCampaignCollection);

        $action(
            $marketingCampaignCollection,
            array_merge(
                $request->safe()->except(['laboratory_test_ids']),
                ['laboratory_test_ids' => $request->input('laboratory_test_ids', [])],
            ),
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Colección actualizada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'brands' => LaboratoryBrand::brandsData(),
            'productSearchUrl' => route('admin.marketing-campaigns.product-search'),
        ];
    }

    private function ensureCollectionBelongsToCampaign(
        MarketingCampaign $campaign,
        MarketingCampaignCollection $collection,
    ): void {
        abort_unless(
            (int) $collection->marketing_campaign_id === (int) $campaign->id,
            404,
        );
    }
}
