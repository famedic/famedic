<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use App\Services\Marketing\Targets\MarketingCampaignLandingProductMapper;
use App\Services\Marketing\Targets\MarketingCampaignResolvedTarget;

/**
 * Resuelve los productos "destacados" (primary) y "relacionados" (related) de la
 * landing de un enlace de campaña.
 *
 * Reglas (Bloque 3.2):
 * - Si el enlace tiene productos primary explícitos (marketing_campaign_link_products),
 *   se usan esos en el orden configurado.
 * - Si no hay primary explícitos, se usa el fallback que cada resolver de target ya
 *   calculó (brand: máx. 6; category: máx. 12; product: el producto principal;
 *   collection: los items de la colección).
 * - Los relacionados sólo se muestran si fueron configurados explícitamente; no hay
 *   fallback automático para "related".
 */
class MarketingCampaignLandingProductsResolver
{
    public function __construct(
        private readonly MarketingCampaignLandingProductMapper $productMapper,
    ) {}

    /**
     * @param  array<string, string>  $allowedQuery
     * @return array{primary: list<array<string, mixed>>, related: list<array<string, mixed>>}
     */
    public function resolve(
        MarketingCampaignLink $link,
        MarketingCampaignResolvedTarget $resolved,
        array $allowedQuery = [],
    ): array {
        $primaryRecords = $link->primaryLandingProducts()
            ->with('laboratoryTest.laboratoryTestCategory')
            ->get();

        $primary = $primaryRecords->isNotEmpty()
            ? $this->mapRecords($primaryRecords, $allowedQuery)
            : $resolved->products;

        $relatedRecords = $link->relatedLandingProducts()
            ->with('laboratoryTest.laboratoryTestCategory')
            ->get();

        $related = $this->mapRecords($relatedRecords, $allowedQuery);

        return [
            'primary' => array_values($primary),
            'related' => array_values($related),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\MarketingCampaignLinkProduct>  $records
     * @param  array<string, string>  $allowedQuery
     * @return list<array<string, mixed>>
     */
    private function mapRecords($records, array $allowedQuery): array
    {
        return $records
            ->map(fn ($record) => $record->laboratoryTest)
            ->filter()
            ->map(fn ($test) => $this->productMapper->map($test, $allowedQuery))
            ->values()
            ->all();
    }
}
