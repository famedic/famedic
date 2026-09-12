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
use App\Services\Marketing\MarketingCampaignCollectionLinkResolver;
use Illuminate\Http\JsonResponse;
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
    ): RedirectResponse|JsonResponse {
        $this->authorize('view', $marketingCampaign);

        $collection = $action(array_merge(
            $request->safe()->except(['marketing_campaign_id']),
            [
                'marketing_campaign_id' => $marketingCampaign->id,
                'laboratory_test_ids' => $request->input('laboratory_test_ids', []),
            ],
        ));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Colección creada.',
                'collection' => $this->collectionPayload($collection->loadCount('items')),
            ]);
        }

        if ($request->boolean('return_to_campaign')) {
            return redirect()
                ->route('admin.marketing-campaigns.show', $marketingCampaign)
                ->flashMessage('Colección creada.');
        }

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Colección creada.');
    }

    public function edit(
        MarketingCampaign $marketingCampaign,
        MarketingCampaignCollection $marketingCampaignCollection,
        MarketingCampaignCollectionLinkResolver $linkResolver,
    ): Response {
        $this->ensureCollectionBelongsToCampaign($marketingCampaign, $marketingCampaignCollection);
        $this->authorize('update', $marketingCampaignCollection);

        $marketingCampaignCollection->load(['orderedItems.laboratoryTest.laboratoryTestCategory']);
        $marketingCampaignCollection->loadCount('items');

        return Inertia::render('Admin/MarketingCampaigns/Collections/Edit', [
            'campaign' => [
                'id' => $marketingCampaign->id,
                'name' => $marketingCampaign->name,
            ],
            'collection' => $this->collectionPayload($marketingCampaignCollection),
            'selectedItems' => $this->selectedItemsPayload($marketingCampaignCollection),
            'usingLinks' => $linkResolver->linkPayloads($marketingCampaignCollection),
            'usingLinksCount' => $linkResolver->countForCollection($marketingCampaignCollection),
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

        $redirect = route('admin.marketing-campaigns.show', $marketingCampaign);

        if ($request->boolean('return_to_campaign')) {
            return redirect($redirect)->flashMessage('Colección actualizada.');
        }

        return redirect()
            ->route('admin.marketing-campaigns.collections.edit', [
                $marketingCampaign,
                $marketingCampaignCollection,
            ])
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
            'maxCollectionItems' => 50,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPayload(MarketingCampaignCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'public_title' => $collection->public_title,
            'public_description' => $collection->public_description,
            'laboratory_brand' => $collection->laboratory_brand?->value ?? $collection->laboratory_brand,
            'laboratory_brand_label' => $collection->laboratory_brand?->label(),
            'is_active' => $collection->is_active,
            'items_count' => (int) ($collection->items_count ?? $collection->orderedItems?->count() ?? 0),
            'updated_at' => $collection->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectedItemsPayload(MarketingCampaignCollection $collection): array
    {
        return $collection->orderedItems
            ->map(function ($item) {
                $test = $item->laboratoryTest;

                if (! $test) {
                    return null;
                }

                return [
                    'id' => $test->id,
                    'name' => $test->name,
                    'other_name' => $test->other_name,
                    'brand' => $test->brand?->value ?? $test->brand,
                    'brand_label' => $test->brand?->label(),
                    'category_name' => $test->laboratoryTestCategory?->name,
                    'famedic_price_cents' => $test->famedic_price_cents,
                    'public_price_cents' => $test->public_price_cents,
                    'requires_appointment' => (bool) $test->requires_appointment,
                ];
            })
            ->filter()
            ->values()
            ->all();
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
