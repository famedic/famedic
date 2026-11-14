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
        $package = $this->resource->loadMissing(['packageTest.laboratoryTestCategory', 'components', 'descriptors']);
        $test = $package->packageTest;

        $studies = $package->components->map(fn ($component) => [
            'type' => 'study',
            'id' => $component->id,
            'name' => $component->name,
            'gda_id' => $component->gda_id,
            'sort_order' => (int) $component->pivot->sort_order,
            'validation_status' => 'validated',
        ]);

        $descriptors = $package->descriptors->map(fn ($descriptor) => [
            'type' => 'commercial_descriptor',
            'id' => null,
            'name' => $descriptor->description,
            'gda_id' => null,
            'sort_order' => (int) $descriptor->sort_order,
            'validation_status' => $descriptor->validation_status,
            'validation_reference' => $descriptor->validation_reference,
        ]);

        $composition = $studies->concat($descriptors)->sortBy('sort_order')->values();
        $hasUnconfirmedDescriptor = $package->descriptors->contains(
            fn ($descriptor) => $descriptor->validation_status !== 'validated',
        );

        return [
            'id' => $test->id,
            'name' => $test->name,
            'brand' => $test->brand->value,
            'category' => $test->laboratoryTestCategory?->name,
            'package_classification' => 'explicit',
            'composition' => $composition->all(),
            'composition_status' => $hasUnconfirmedDescriptor ? 'incomplete' : 'validated',
            'pricing' => (new LaboratoryPricingResource($test))->resolve($request),
            'available' => ! $test->trashed(),
        ];
    }
}
