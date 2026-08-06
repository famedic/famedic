<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignLinkAction;
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
                $request->safe()->except(['marketing_campaign_id']),
                ['marketing_campaign_id' => $marketingCampaign->id],
            ),
            $request->user()->administrator,
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

        $marketingCampaignLink->load(['aliases' => fn ($query) => $query->orderByDesc('created_at')]);

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
            $request->safe()->all(),
            $request->user()->administrator,
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Enlace actualizado.');
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
                ->orderBy('name')
                ->get(['id', 'name', 'public_title', 'is_active', 'laboratory_brand']),
            'productSearchUrl' => route('admin.marketing-campaigns.product-search'),
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
