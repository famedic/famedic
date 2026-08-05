<?php

namespace App\Services\ClinicalMatching\Catalog;

/**
 * Routes catalog queries to domain adapters (lab now, pharmacy later, etc.).
 */
class CompositeCatalogAdapter implements CatalogAdapterInterface
{
    /**
     * @param  list<CatalogAdapterInterface>  $adapters
     */
    public function __construct(
        private array $adapters,
    ) {}

    public function supports(string $type): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $searchTerms
     * @return list<CatalogItem>
     */
    public function searchCandidates(array $searchTerms, string $type, int $limit = 40): array
    {
        $byId = [];

        foreach ($this->adapters as $adapter) {
            if (! $adapter->supports($type)) {
                continue;
            }

            // When type=all, ask each adapter for its primary domain.
            $adapterType = $type;
            if ($type === 'all') {
                if ($adapter instanceof LaboratoryCatalogAdapter) {
                    $adapterType = 'laboratory';
                } elseif ($adapter instanceof NullMedicationCatalogAdapter) {
                    $adapterType = 'medication';
                }
            }

            foreach ($adapter->searchCandidates($searchTerms, $adapterType, $limit) as $item) {
                $byId[$item->id] = $item;
            }
        }

        return array_values($byId);
    }
}
