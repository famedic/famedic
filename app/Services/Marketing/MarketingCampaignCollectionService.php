<?php

namespace App\Services\Marketing;

use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingCampaignCollectionService
{
    /**
     * Sincroniza los items de una colección.
     *
     * Reglas:
     * - lista vacía válida (limpia items);
     * - IDs duplicados se rechazan (no se deduplican);
     * - todos deben pertenecer a la marca de la colección;
     * - el orden de entrada se conserva en `position`.
     *
     * @param  list<int|string>  $laboratoryTestIds
     */
    public function syncItems(
        MarketingCampaignCollection $collection,
        array $laboratoryTestIds,
    ): MarketingCampaignCollection {
        $ids = array_map(static fn ($id): int => (int) $id, array_values($laboratoryTestIds));

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'laboratory_test_ids' => 'No se permiten estudios duplicados en la colección.',
            ]);
        }

        if ($ids === []) {
            return DB::transaction(function () use ($collection) {
                $lockedCollection = MarketingCampaignCollection::query()
                    ->lockForUpdate()
                    ->findOrFail($collection->getKey());

                $lockedCollection->laboratoryTests()->sync([]);

                return $lockedCollection->load(['orderedItems.laboratoryTest']);
            });
        }

        $tests = LaboratoryTest::query()
            ->whereKey($ids)
            ->get(['id', 'brand']);

        if ($tests->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'laboratory_test_ids' => 'Uno o más estudios no existen.',
            ]);
        }

        $expectedBrand = $collection->laboratory_brand instanceof \BackedEnum
            ? $collection->laboratory_brand->value
            : (string) $collection->laboratory_brand;

        $hasOtherBrand = $tests->contains(
            function (LaboratoryTest $test) use ($expectedBrand): bool {
                $brand = $test->brand instanceof \BackedEnum
                    ? $test->brand->value
                    : (string) $test->brand;

                return $brand !== $expectedBrand;
            }
        );

        if ($hasOtherBrand) {
            throw ValidationException::withMessages([
                'laboratory_test_ids' => 'Todos los estudios deben pertenecer a la marca de la colección.',
            ]);
        }

        return DB::transaction(function () use ($collection, $ids) {
            $lockedCollection = MarketingCampaignCollection::query()
                ->lockForUpdate()
                ->findOrFail($collection->getKey());

            $syncPayload = collect($ids)
                ->mapWithKeys(fn (int $id, int $position) => [
                    $id => ['position' => $position],
                ])
                ->all();

            $lockedCollection->laboratoryTests()->sync($syncPayload);

            return $lockedCollection->load(['orderedItems.laboratoryTest']);
        });
    }
}
