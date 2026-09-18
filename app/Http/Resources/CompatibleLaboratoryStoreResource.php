<?php

namespace App\Http\Resources;

use App\Support\LaboratoryRequirements\BranchMatchResult;
use App\Support\LaboratoryRequirements\CartRequirement;
use App\Support\LaboratoryRequirements\CartRequirements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompatibleLaboratoryStoreResource extends JsonResource
{
    public function __construct(
        BranchMatchResult $resource,
        private readonly CartRequirements $requirements,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var BranchMatchResult $result */
        $result = $this->resource;
        $branch = $result->branch;

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'brand' => $result->brand,
            'address' => $branch->address,
            'municipality' => $branch->municipality,
            'city' => $branch->city,
            'state' => $branch->state,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'phone' => $branch->phone,
            'isCompatible' => $result->isCompatible,
            'matchLevel' => $result->matchLevel,
            'matchedRequirements' => $this->requirements($result->matchedRequirements),
            'missingRequirements' => $this->requirements($result->missingRequirements),
            'distanceKm' => $result->distanceKm,
            'hours' => [
                'isOpenNow' => $result->hours->isOpenNow,
                'opensOnRequestedDate' => $result->hours->opensOnRequestedDate,
                'hoursSummary' => $result->hours->hoursSummary,
                'appointmentAvailability' => $result->hours->appointmentAvailability,
            ],
            'groups' => array_map(fn ($group) => [
                'groupKey' => $group->groupKey,
                'operator' => $group->operator,
                'required' => $group->required,
                'isSatisfied' => $group->isSatisfied,
                'matchedCapabilities' => $this->requirements($group->matchedCapabilities),
                'missingCapabilities' => $this->requirements($group->missingCapabilities),
                'confidence' => $group->confidence,
                'sources' => $group->sources,
            ], $result->groups),
            'reasons' => $result->reasons,
        ];
    }

    private function requirements(array $slugs): array
    {
        $index = collect($this->requirements->requirements)
            ->keyBy(fn (CartRequirement $requirement) => $requirement->capabilitySlug);

        return collect($slugs)
            ->map(function (string $slug) use ($index): array {
                /** @var CartRequirement|null $requirement */
                $requirement = $index->get($slug);

                return [
                    'capability' => $slug,
                    'label' => $requirement?->label,
                    'sources' => $requirement?->sources ?? [],
                    'confidences' => $requirement?->confidences ?? [],
                    'studies' => collect($requirement?->studies ?? [])
                        ->map(fn (array $study) => [
                            'test_id' => $study['test_id'] ?? null,
                            'gda_id' => $study['gda_id'] ?? null,
                            'name' => $study['name'] ?? null,
                            'brand' => $study['brand'] ?? null,
                        ])
                        ->values()
                        ->all(),
                    'components' => collect($requirement?->components ?? [])
                        ->map(fn (array $component) => [
                            'rawText' => $component['raw_text'] ?? null,
                            'packageName' => $component['package_name'] ?? null,
                            'componentIndex' => $component['component_index'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
