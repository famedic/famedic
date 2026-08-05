<?php

namespace App\Services\ClinicalMatching\Catalog;

/**
 * Port for catalog data sources. Engine depends on this, never on Eloquent.
 */
interface CatalogAdapterInterface
{
    /**
     * Retrieve a limited candidate set for ranking. Must not dump the full catalog.
     *
     * @param  list<string>  $searchTerms  Normalized / expanded query variants
     * @return list<CatalogItem>
     */
    public function searchCandidates(array $searchTerms, string $type, int $limit = 40): array;

    public function supports(string $type): bool;
}
