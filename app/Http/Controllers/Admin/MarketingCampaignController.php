<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\CreateMarketingCampaignAction;
use App\Actions\Admin\MarketingCampaigns\UpdateMarketingCampaignAction;
use App\Enums\MarketingCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\IndexMarketingCampaignRequest;
use App\Http\Requests\Admin\MarketingCampaigns\StoreMarketingCampaignRequest;
use App\Http\Requests\Admin\MarketingCampaigns\UpdateMarketingCampaignRequest;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
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
        ]);
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
            'links' => fn ($query) => $query->orderByDesc('created_at'),
            'collections' => fn ($query) => $query->withCount('items')->orderByDesc('created_at'),
            'createdBy.user',
            'updatedBy.user',
        ]);

        $user = request()->user();
        $canEdit = $user->can('update', $marketingCampaign);
        $canArchive = $user->can('archive', $marketingCampaign) && ! $marketingCampaign->isArchived();
        $canCreateChildren = $user->can('create', MarketingCampaignLink::class)
            && ! $marketingCampaign->isArchived();

        return Inertia::render('Admin/MarketingCampaigns/Show', [
            'campaign' => $this->campaignPayload($marketingCampaign),
            'links' => $marketingCampaign->links->map(fn ($link) => [
                'id' => $link->id,
                'name' => $link->name,
                'slug' => $link->slug,
                'status' => $link->status?->value ?? $link->status,
                'status_label' => $link->status?->label(),
                'target_type' => $link->target_type?->value ?? $link->target_type,
                'target_type_label' => $link->target_type?->label(),
                'starts_at' => $link->starts_at,
                'ends_at' => $link->ends_at,
                'created_at' => $link->created_at,
            ]),
            'collections' => $marketingCampaign->collections->map(fn ($collection) => [
                'id' => $collection->id,
                'name' => $collection->name,
                'public_title' => $collection->public_title,
                'laboratory_brand' => $collection->laboratory_brand?->value ?? $collection->laboratory_brand,
                'laboratory_brand_label' => $collection->laboratory_brand?->label(),
                'is_active' => $collection->is_active,
                'items_count' => $collection->items_count,
                'created_at' => $collection->created_at,
            ]),
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
}
