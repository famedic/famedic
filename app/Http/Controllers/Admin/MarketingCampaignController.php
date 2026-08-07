<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignAction;
use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignSetupAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignAction;
use App\Enums\MarketingCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\IndexMarketingCampaignRequest;
use App\Http\Requests\Admin\MarketingCampaigns\StoreMarketingCampaignRequest;
use App\Http\Requests\Admin\MarketingCampaigns\StoreMarketingCampaignSetupRequest;
use App\Http\Requests\Admin\MarketingCampaigns\UpdateMarketingCampaignRequest;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignCollectionLinkResolver;
use App\Services\Marketing\MarketingCampaignDashboardPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketingCampaignController extends Controller
{
    public function index(IndexMarketingCampaignRequest $request): Response
    {
        $filters = collect($request->safe()->only([
            'search',
            'status',
            'starts_at',
            'ends_at',
            'sort',
            'direction',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $user = $request->user();
        $canCreate = $user->can('create', MarketingCampaign::class);

        $campaigns = MarketingCampaign::query()
            ->withCount(['links', 'collections'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($filters['status'] ?? null, function ($query, string $status) {
                $query->where('status', $status);
            })
            ->when($filters['starts_at'] ?? null, function ($query, string $startsAt) {
                $query->whereDate('starts_at', $startsAt);
            })
            ->when($filters['ends_at'] ?? null, function ($query, string $endsAt) {
                $query->whereDate('ends_at', $endsAt);
            })
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString()
            ->through(function (MarketingCampaign $campaign) use ($user) {
                $canEdit = $user->can('update', $campaign);
                $canArchive = $user->can('archive', $campaign) && ! $campaign->isArchived();

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status?->value ?? $campaign->status,
                    'status_label' => $campaign->status?->label(),
                    'starts_at' => $campaign->starts_at,
                    'ends_at' => $campaign->ends_at,
                    'links_count' => $campaign->links_count,
                    'collections_count' => $campaign->collections_count,
                    'updated_at' => $campaign->updated_at,
                    'created_at' => $campaign->created_at,
                    'can_edit' => $canEdit,
                    'can_archive' => $canArchive,
                ];
            });

        return Inertia::render('Admin/MarketingCampaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'starts_at' => $filters['starts_at'] ?? '',
                'ends_at' => $filters['ends_at'] ?? '',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'statusOptions' => collect(MarketingCampaignStatus::cases())
                ->map(fn (MarketingCampaignStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values()
                ->all(),
            'capabilities' => [
                'canView' => true,
                'canCreate' => $canCreate,
                'canEdit' => $canCreate,
                'canArchive' => $canCreate,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', MarketingCampaign::class);

        return Inertia::render('Admin/MarketingCampaigns/Create', [
            'statusOptions' => $this->campaignStatusOptions(),
            'linkStatusOptions' => $this->linkStatusOptions(),
            'brands' => \App\Enums\LaboratoryBrand::brandsData(),
            'categories' => \App\Models\LaboratoryTestCategory::query()->orderBy('name')->get(['id', 'name']),
            'productSearchUrl' => route('admin.marketing-campaigns.product-search'),
            'utmPresets' => $this->utmPresets(),
            'promotionOptions' => $this->promotionOptions(),
        ]);
    }

    public function storeSetup(
        StoreMarketingCampaignSetupRequest $request,
        CreateMarketingCampaignSetupAction $action,
    ): RedirectResponse {
        $payload = $request->setupPayload();
        $linkInput = (array) ($payload['link'] ?? []);

        $result = $action(
            [
                'activate' => $payload['activate'],
                'campaign' => $payload['campaign'],
                'collection' => $payload['collection'],
                'link' => array_merge($linkInput, [
                    'gallery_items' => $linkInput['gallery_items'] ?? [],
                ]),
            ],
            $request->user()->administrator,
            $request->file('link.hero_image'),
            $request->file('link.gallery_uploads', []),
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $result['campaign'])
            ->flashMessage('Campaña y enlace creados.');
    }

    public function store(
        StoreMarketingCampaignRequest $request,
        CreateMarketingCampaignAction $action,
    ): RedirectResponse {
        $campaign = $action(
            $request->safe()->only(['name', 'description', 'status', 'starts_at', 'ends_at']),
            $request->user()->administrator,
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $campaign)
            ->flashMessage('Campaña creada.');
    }

    public function show(MarketingCampaign $marketingCampaign): Response
    {
        $this->authorize('view', $marketingCampaign);

        $marketingCampaign->loadCount(['links', 'collections']);
        $marketingCampaign->load([
            'links' => fn ($query) => $query
                ->withCount('primaryLandingProducts')
                ->orderByDesc('created_at'),
            'collections' => fn ($query) => $query->withCount('items')->orderByDesc('created_at'),
            'createdBy.user',
            'updatedBy.user',
        ]);

        $presenter = app(MarketingCampaignDashboardPresenter::class);
        $links = $marketingCampaign->links;
        $linkResolver = app(MarketingCampaignCollectionLinkResolver::class);
        $collectionIds = $marketingCampaign->collections->pluck('id')->all();
        $collectionLinkCounts = $linkResolver->countsForCampaign(
            $marketingCampaign->id,
            $collectionIds,
        );

        $user = request()->user();
        $canEdit = $user->can('update', $marketingCampaign);
        $canArchive = $user->can('archive', $marketingCampaign) && ! $marketingCampaign->isArchived();
        $canCreateChildren = $user->can('create', MarketingCampaignLink::class)
            && ! $marketingCampaign->isArchived();

        return Inertia::render('Admin/MarketingCampaigns/Show', [
            'campaign' => $this->campaignPayload($marketingCampaign),
            'links' => $presenter->links($links),
            'collections' => $marketingCampaign->collections->map(function ($collection) use (
                $linkResolver,
                $collectionLinkCounts,
                $marketingCampaign,
            ) {
                $usingLinks = $linkResolver->linkPayloads($collection, 1);
                $primaryLink = $usingLinks[0] ?? null;

                return [
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'public_title' => $collection->public_title,
                    'laboratory_brand' => $collection->laboratory_brand?->value ?? $collection->laboratory_brand,
                    'laboratory_brand_label' => $collection->laboratory_brand?->label(),
                    'is_active' => $collection->is_active,
                    'items_count' => $collection->items_count,
                    'links_count' => $collectionLinkCounts[$collection->id] ?? 0,
                    'updated_at' => $collection->updated_at,
                    'created_at' => $collection->created_at,
                    'primary_link' => $primaryLink,
                    'edit_url' => route('admin.marketing-campaigns.collections.edit', [
                        $marketingCampaign,
                        $collection,
                    ]),
                    'create_link_url' => route('admin.marketing-campaigns.links.create', $marketingCampaign),
                ];
            }),
            'summary' => $presenter->summary($marketingCampaign, $links),
            'checklist' => $presenter->checklist($marketingCampaign, $links),
            'capabilities' => [
                'canView' => true,
                'canEdit' => $canEdit,
                'canArchive' => $canArchive,
                'canCreateLink' => $canCreateChildren && $user->can('create', MarketingCampaignLink::class),
                'canCreateCollection' => $canCreateChildren && $user->can('create', MarketingCampaignCollection::class),
            ],
        ]);
    }

    public function edit(MarketingCampaign $marketingCampaign): Response
    {
        $this->authorize('update', $marketingCampaign);

        $marketingCampaign->loadCount(['links', 'collections']);
        $marketingCampaign->load(['createdBy.user', 'updatedBy.user']);

        return Inertia::render('Admin/MarketingCampaigns/Edit', [
            'campaign' => $this->campaignPayload($marketingCampaign),
            'statusOptions' => $this->campaignStatusOptions(),
            'capabilities' => [
                'canView' => true,
                'canEdit' => true,
                'canArchive' => request()->user()->can('archive', $marketingCampaign)
                    && ! $marketingCampaign->isArchived(),
            ],
        ]);
    }

    public function update(
        UpdateMarketingCampaignRequest $request,
        MarketingCampaign $marketingCampaign,
        UpdateMarketingCampaignAction $action,
    ): RedirectResponse {
        $action(
            $marketingCampaign,
            $request->safe()->only(['name', 'description', 'status', 'starts_at', 'ends_at']),
            $request->user()->administrator,
        );

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Campaña actualizada.');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function campaignStatusOptions(): array
    {
        return collect(MarketingCampaignStatus::cases())
            ->reject(fn (MarketingCampaignStatus $status) => $status === MarketingCampaignStatus::Archived)
            ->map(fn (MarketingCampaignStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayload(MarketingCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'status' => $campaign->status?->value ?? $campaign->status,
            'status_label' => $campaign->status?->label(),
            'starts_at' => $campaign->starts_at,
            'ends_at' => $campaign->ends_at,
            'created_at' => $campaign->created_at,
            'updated_at' => $campaign->updated_at,
            'created_by_name' => $campaign->createdBy?->user?->name,
            'updated_by_name' => $campaign->updatedBy?->user?->name,
            'links_count' => (int) ($campaign->links_count ?? 0),
            'collections_count' => (int) ($campaign->collections_count ?? 0),
            'is_archived' => $campaign->isArchived(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function linkStatusOptions(): array
    {
        return collect(\App\Enums\MarketingCampaignLinkStatus::cases())
            ->reject(fn ($status) => $status === \App\Enums\MarketingCampaignLinkStatus::Archived)
            ->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->all();
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
}
