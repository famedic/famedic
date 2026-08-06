<?php

namespace App\Services\ClinicalMatching\Catalog;

/**
 * Pharmacy is out of scope this sprint. Keeps the port open for a future Vitau adapter.
 */
class NullMedicationCatalogAdapter implements CatalogAdapterInterface
{
    public function supports(string $type): bool
    {
        return in_array($type, ['medication', 'all'], true);
    }

    /**
     * @param  list<string>  $searchTerms
     * @return list<CatalogItem>
     */
    public function searchCandidates(array $searchTerms, string $type, int $limit = 40): array
    {
        return [];
    }
}
