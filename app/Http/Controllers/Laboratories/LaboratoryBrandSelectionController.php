<?php

namespace App\Http\Controllers\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTestCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryBrandSelectionController extends Controller
{
    public function __invoke(Request $request)
    {
        $activeStores = LaboratoryStore::query()
            ->where('is_active', true)
            ->get(['brand', 'state']);

        $statesByBrand = $activeStores
            ->groupBy(fn (LaboratoryStore $store) => $store->brand->value)
            ->map(fn ($stores) => $stores
                ->pluck('state')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all());

        $countsByBrand = $activeStores
            ->countBy(fn (LaboratoryStore $store) => $store->brand->value);

        $brandOrder = [
            LaboratoryBrand::SWISSLAB,
            LaboratoryBrand::OLAB,
            LaboratoryBrand::AZTECA,
            LaboratoryBrand::JENNER,
            LaboratoryBrand::LIACSA,
        ];

        return Inertia::render(
            'LaboratoryBrandSelection',
            [
                'brands' => collect($brandOrder)->map(fn (LaboratoryBrand $brand) => [
                    ...LaboratoryBrand::brandData($brand),
                    'brand' => $brand->value,
                    'active_store_count' => (int) ($countsByBrand[$brand->value] ?? 0),
                    'states' => $statesByBrand[$brand->value] ?? [],
                ])->values(),
                'states' => LaboratoryStore::query()
                    ->where('is_active', true)
                    ->select('state')
                    ->distinct()
                    ->orderBy('state')
                    ->pluck('state')
                    ->toArray(),
                'laboratoryTestCategories' => collect([
                    LaboratoryTestCategory::find(12),
                ])->filter()->merge(
                    LaboratoryTestCategory::whereNotIn('id', [12, 13])->get()
                )->values()->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                    ];
                }),
            ]
        );
    }
}
