<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignStatus;
use App\Models\MarketingCampaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

class MarketingCampaignDashboardPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(MarketingCampaign $campaign, Collection $links): array
    {
        $configuredProducts = $links->sum(
            fn ($link) => (int) ($link->primary_landing_products_count ?? $link->primaryLandingProducts?->count() ?? 0),
        );

        $checklist = $this->checklist($campaign, $links);
        $completed = collect($checklist)->whereIn('status', ['complete', 'na'])->count();
        $total = collect($checklist)->count();

        return [
            'links_count' => (int) ($campaign->links_count ?? $links->count()),
            'collections_count' => (int) ($campaign->collections_count ?? 0),
            'configured_products_count' => $configuredProducts,
            'completeness_percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'primary_link' => $this->linkPayload($links->first()),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail?: string}>
     */
    public function checklist(MarketingCampaign $campaign, Collection $links): array
    {
        $primaryLink = $links->first();

        return [
            [
                'key' => 'general',
                'label' => 'Información general',
                'status' => filled($campaign->name) ? 'complete' : 'pending',
                'detail' => filled($campaign->description) ? 'Descripción configurada' : 'Agrega contexto interno opcional',
            ],
            [
                'key' => 'link',
                'label' => 'Al menos un enlace',
                'status' => $links->isNotEmpty() ? 'complete' : 'pending',
            ],
            [
                'key' => 'target',
                'label' => 'Destino configurado',
                'status' => $primaryLink && filled($primaryLink->target_type) ? 'complete' : ($links->isEmpty() ? 'na' : 'pending'),
            ],
            [
                'key' => 'products',
                'label' => 'Productos configurados',
                'status' => $this->productsStatus($primaryLink),
            ],
            [
                'key' => 'content',
                'label' => 'Contenido de landing',
                'status' => $primaryLink && filled($primaryLink->public_title) ? 'complete' : ($links->isEmpty() ? 'na' : 'pending'),
            ],
            [
                'key' => 'hero',
                'label' => 'Imagen principal',
                'status' => $primaryLink && $primaryLink->resolvedHeroImageUrl() ? 'complete' : 'recommended',
                'detail' => 'Opcional, mejora la conversión',
            ],
            [
                'key' => 'utms',
                'label' => 'UTMs',
                'status' => $primaryLink && filled($primaryLink->utm_source) && filled($primaryLink->utm_medium)
                    ? 'complete'
                    : ($links->isEmpty() ? 'na' : 'recommended'),
            ],
            [
                'key' => 'active',
                'label' => 'Campaña activa',
                'status' => $campaign->status === MarketingCampaignStatus::Active ? 'complete' : 'pending',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function linkPayload(?object $link): ?array
    {
        if (! $link) {
            return null;
        }

        return [
            'id' => $link->id,
            'name' => $link->name,
            'slug' => $link->slug,
            'public_url' => URL::to('/c/'.$link->slug),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function links(Collection $links): array
    {
        return $links->map(fn ($link) => [
            'id' => $link->id,
            'name' => $link->name,
            'slug' => $link->slug,
            'public_url' => URL::to('/c/'.$link->slug),
            'status' => $link->status?->value ?? $link->status,
            'status_label' => $link->status?->label(),
            'target_type' => $link->target_type?->value ?? $link->target_type,
            'target_type_label' => $link->target_type?->label(),
            'starts_at' => $link->starts_at,
            'ends_at' => $link->ends_at,
            'created_at' => $link->created_at,
        ])->values()->all();
    }

    private function productsStatus(?object $link): string
    {
        if (! $link) {
            return 'na';
        }

        $count = (int) ($link->primary_landing_products_count ?? $link->primaryLandingProducts?->count() ?? 0);

        if ($count > 0) {
            return 'complete';
        }

        return filled($link->target_type) ? 'recommended' : 'pending';
    }
}
