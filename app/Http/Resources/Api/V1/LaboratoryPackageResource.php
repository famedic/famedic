<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryTestPackage;
use App\Models\LaboratoryTestPackageDescriptor;
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
            // B02: un estudio vinculado solo es 'validated' cuando la equivalencia
            // comercial fue aprobada; la mera vinculación no certifica nada.
            'validation_status' => $component->pivot->validation_status
                ?? LaboratoryTestPackageDescriptor::STATUS_PENDING,
            'validation_reference' => $component->pivot->validation_reference,
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
        $hasUnconfirmedElement = $composition->contains(
            fn (array $element) => $element['validation_status'] !== LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
        );

        return [
            'id' => $test->id,
            // RC2 B02 (D8): additive discriminator shared with the laboratory-tests catalog.
            'item_type' => 'package',
            'name' => $test->name,
            'brand' => $test->brand->value,
            'category' => $test->laboratoryTestCategory?->name,
            'package_classification' => 'explicit',
            'composition' => $composition->all(),
            'composition_status' => $hasUnconfirmedElement ? 'incomplete' : 'validated',
            'pricing' => (new LaboratoryPricingResource($test))->resolve($request),
            'available' => ! $test->trashed(),
        ];
    }
}
