<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryTestPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryTestPackage */
class LaboratoryPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->resource->loadMissing(['packageTest.laboratoryTestCategory', 'components']);
        $test = $package->packageTest;

        return [
            'id' => $test->id,
            'name' => $test->name,
            'brand' => $test->brand->value,
            'category' => $test->laboratoryTestCategory?->name,
            'package_classification' => 'explicit',
            'composition' => $package->components->map(fn ($component) => [
                'id' => $component->id,
                'name' => $component->name,
                'gda_id' => $component->gda_id,
                'sort_order' => (int) $component->pivot->sort_order,
            ])->values()->all(),
            'pricing' => (new LaboratoryPricingResource($test))->resolve($request),
            'available' => ! $test->trashed(),
        ];
    }
}
