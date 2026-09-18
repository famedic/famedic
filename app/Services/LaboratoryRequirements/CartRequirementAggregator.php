<?php

namespace App\Services\LaboratoryRequirements;

use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Support\LaboratoryRequirements\CartRequirement;
use App\Support\LaboratoryRequirements\CartRequirementGroup;
use App\Support\LaboratoryRequirements\CartRequirements;
use App\Support\LaboratoryRequirements\ResolvedRequirement;
use App\Support\LaboratoryRequirements\ResolvedRequirementGroup;
use App\Support\LaboratoryRequirements\ResolvedStudyRequirements;
use Illuminate\Support\Collection;

class CartRequirementAggregator
{
    public function __construct(
        private readonly StudyRequirementResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, LaboratoryCartItem>  $items
     */
    public function aggregateItems(Collection $items, ?string $cartId = null): CartRequirements
    {
        $items->each(fn (LaboratoryCartItem $item) => $item->loadMissing('laboratoryTest.laboratoryTestCategory'));
        $resolutionCache = [];
        $resolvedStudies = [];

        foreach ($items as $item) {
            $test = $item->laboratoryTest;

            if (! $test instanceof LaboratoryTest) {
                continue;
            }

            $resolved = $resolutionCache[$test->id] ??= $this->resolver->resolve($test);
            $resolvedStudies[] = [
                'resolved' => $resolved,
                'study' => $this->studyEvidence($test, [
                    'cart_item_id' => $item->id,
                    'quantity' => 1,
                ]),
            ];
        }

        return $this->aggregateResolved(collect($resolvedStudies), $cartId);
    }

    /**
     * @param  Collection<int, LaboratoryTest>  $studies
     */
    public function aggregateStudies(Collection $studies, ?string $cartId = null): CartRequirements
    {
        $studies->each(fn (LaboratoryTest $test) => $test->loadMissing('laboratoryTestCategory'));
        $resolutionCache = [];

        return $this->aggregateResolved(
            $studies->map(function (LaboratoryTest $test) use (&$resolutionCache): array {
                $resolved = $resolutionCache[$test->id] ??= $this->resolver->resolve($test);

                return [
                    'resolved' => $resolved,
                    'study' => $this->studyEvidence($test, ['quantity' => 1]),
                ];
            }),
            $cartId,
        );
    }

    /**
     * Core API for tests and future callers that already resolved studies.
     *
     * @param  Collection<int, ResolvedStudyRequirements|array{resolved: ResolvedStudyRequirements, study?: array<string, mixed>}>  $resolvedStudies
     */
    public function aggregateResolved(Collection $resolvedStudies, ?string $cartId = null): CartRequirements
    {
        $studies = [];
        $groups = [];
        $flatRequirements = [];
        $unresolvedReasons = [];

        foreach ($resolvedStudies as $index => $entry) {
            $resolved = $entry instanceof ResolvedStudyRequirements ? $entry : $entry['resolved'];
            $study = $entry instanceof ResolvedStudyRequirements
                ? $this->studyFromResolved($resolved, $index)
                : ($entry['study'] ?? $this->studyFromResolved($resolved, $index));

            $studies[] = array_merge($study, [
                'confidence' => $resolved->confidence,
                'unresolved_reasons' => $resolved->unresolvedReasons,
            ]);

            array_push($unresolvedReasons, ...$resolved->unresolvedReasons);

            foreach ($resolved->groups as $group) {
                $logicalKey = $this->logicalGroupKey($group);
                $groups[$logicalKey] = $this->mergeGroup($groups[$logicalKey] ?? null, $group, $study);

                foreach ($group->requirements as $requirement) {
                    $requirementKey = $requirement->capabilitySlug ?? 'unknown:'.$logicalKey;
                    $flatRequirements[$requirementKey] = $this->mergeRequirement(
                        $flatRequirements[$requirementKey] ?? null,
                        $requirement,
                        $study,
                    );
                }
            }
        }

        $groups = collect($groups)->sortKeys()->values()->all();
        $flatRequirements = collect($flatRequirements)->sortKeys()->values()->all();
        $unresolvedReasons = array_values(array_unique($unresolvedReasons));

        return new CartRequirements(
            cartId: $cartId,
            studies: $studies,
            groups: $groups,
            requirements: $flatRequirements,
            confidence: $this->cartConfidence($groups, $unresolvedReasons),
            isResolvable: $unresolvedReasons === [] && $groups !== [],
            unresolvedReasons: $unresolvedReasons,
            brands: $this->brands($studies, $groups, $flatRequirements),
            evidence: [
                'study_count' => count($studies),
                'group_merge_strategy' => 'same_operator_and_same_sorted_capability_slugs',
                'quantity_semantics' => 'quantity is provenance only; operational compatibility is deduplicated',
            ],
        );
    }

    private function mergeGroup(
        ?CartRequirementGroup $current,
        ResolvedRequirementGroup $incoming,
        array $study,
    ): CartRequirementGroup {
        if ($current === null) {
            return new CartRequirementGroup(
                groupKey: $this->logicalGroupKey($incoming),
                operator: $incoming->operator,
                required: $incoming->required,
                requirements: array_map(fn (ResolvedRequirement $requirement) => $this->cartRequirement($requirement, [$study]), $incoming->requirements),
                sources: [$incoming->source],
                confidences: [$incoming->confidence],
                studies: [$study],
                evidence: [
                    'merged_group_keys' => [$incoming->groupKey],
                    'studies' => [$study],
                ],
            );
        }

        return new CartRequirementGroup(
            groupKey: $current->groupKey,
            operator: $current->operator,
            required: $current->required,
            requirements: $this->mergeGroupRequirements($current->requirements, $incoming->requirements, $study),
            sources: $this->uniqueValues([...$current->sources, $incoming->source]),
            confidences: $this->uniqueValues([...$current->confidences, $incoming->confidence]),
            studies: $this->uniqueStudies([...$current->studies, $study]),
            evidence: [
                'merged_group_keys' => $this->uniqueValues([...($current->evidence['merged_group_keys'] ?? []), $incoming->groupKey]),
                'studies' => $this->uniqueStudies([...($current->evidence['studies'] ?? []), $study]),
            ],
        );
    }

    /**
     * @param  array<int, CartRequirement>  $current
     * @param  array<int, ResolvedRequirement>  $incoming
     * @return array<int, CartRequirement>
     */
    private function mergeGroupRequirements(array $current, array $incoming, array $study): array
    {
        $requirements = collect($current)
            ->keyBy(fn (CartRequirement $requirement) => $requirement->capabilitySlug)
            ->all();

        foreach ($incoming as $requirement) {
            $key = $requirement->capabilitySlug;
            $requirements[$key] = $this->mergeRequirement($requirements[$key] ?? null, $requirement, $study);
        }

        return collect($requirements)->sortKeys()->values()->all();
    }

    private function mergeRequirement(
        ?CartRequirement $current,
        ResolvedRequirement $incoming,
        array $study,
    ): CartRequirement {
        $components = $this->componentsFromRequirement($incoming);

        if ($current === null) {
            return $this->cartRequirement($incoming, [$study], $components);
        }

        return new CartRequirement(
            capabilitySlug: $current->capabilitySlug,
            capabilityId: $current->capabilityId ?? $incoming->capabilityId,
            label: $current->label ?? $incoming->label,
            required: $current->required || $incoming->required,
            sources: $this->uniqueValues([...$current->sources, $incoming->source]),
            confidences: $this->uniqueValues([...$current->confidences, $incoming->confidence]),
            studies: $this->uniqueStudies([...$current->studies, $study]),
            components: $this->uniqueComponents([...$current->components, ...$components]),
            evidence: [
                'requirements_merged' => ($current->evidence['requirements_merged'] ?? 1) + 1,
            ],
        );
    }

    private function cartRequirement(ResolvedRequirement $requirement, array $studies, ?array $components = null): CartRequirement
    {
        return new CartRequirement(
            capabilitySlug: $requirement->capabilitySlug,
            capabilityId: $requirement->capabilityId,
            label: $requirement->label,
            required: $requirement->required,
            sources: [$requirement->source],
            confidences: [$requirement->confidence],
            studies: $this->uniqueStudies($studies),
            components: $components ?? $this->componentsFromRequirement($requirement),
            evidence: [
                'requirements_merged' => 1,
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function componentsFromRequirement(ResolvedRequirement $requirement): array
    {
        return array_values($requirement->evidence['components'] ?? []);
    }

    private function logicalGroupKey(ResolvedRequirementGroup $group): string
    {
        $slugs = collect($group->requirements)
            ->pluck('capabilitySlug')
            ->filter()
            ->sort()
            ->implode('|');

        return $group->operator.':'.$slugs;
    }

    private function studyEvidence(LaboratoryTest $test, array $extra = []): array
    {
        return array_merge([
            'test_id' => $test->id,
            'gda_id' => $test->gda_id,
            'name' => $test->name,
            'brand' => $test->brand?->value,
        ], $extra);
    }

    private function studyFromResolved(ResolvedStudyRequirements $resolved, int $index): array
    {
        return [
            'test_id' => $resolved->testId,
            'gda_id' => $resolved->gdaId,
            'name' => $resolved->testName,
            'brand' => null,
            'quantity' => 1,
            'position' => $index,
        ];
    }

    /**
     * @param  array<int, CartRequirementGroup>  $groups
     * @param  array<int, string>  $unresolvedReasons
     */
    private function cartConfidence(array $groups, array $unresolvedReasons): string
    {
        if ($unresolvedReasons !== [] || $groups === []) {
            return StudyRequirementResolver::CONFIDENCE_UNKNOWN;
        }

        $confidences = collect($groups)->flatMap(fn (CartRequirementGroup $group) => $group->confidences);

        if ($confidences->contains(StudyRequirementResolver::CONFIDENCE_CATEGORY)) {
            return StudyRequirementResolver::CONFIDENCE_CATEGORY;
        }

        return StudyRequirementResolver::CONFIDENCE_MAPPED;
    }

    /**
     * @param  array<int, array<string, mixed>>  $studies
     * @param  array<int, CartRequirementGroup>  $groups
     * @param  array<int, CartRequirement>  $requirements
     * @return array<string, array<string, mixed>>
     */
    private function brands(array $studies, array $groups, array $requirements): array
    {
        return collect($studies)
            ->groupBy(fn (array $study) => $study['brand'] ?? '__unknown')
            ->map(function (Collection $brandStudies) use ($groups, $requirements): array {
                $studyIds = $brandStudies->pluck('test_id')->all();

                return [
                    'studies' => $brandStudies->values()->all(),
                    'groups' => collect($groups)
                        ->filter(fn (CartRequirementGroup $group) => collect($group->studies)->pluck('test_id')->intersect($studyIds)->isNotEmpty())
                        ->map(fn (CartRequirementGroup $group) => $group->groupKey)
                        ->values()
                        ->all(),
                    'requirements' => collect($requirements)
                        ->filter(fn (CartRequirement $requirement) => collect($requirement->studies)->pluck('test_id')->intersect($studyIds)->isNotEmpty())
                        ->map(fn (CartRequirement $requirement) => $requirement->capabilitySlug)
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function uniqueValues(array $values): array
    {
        return collect($values)->filter()->unique()->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $studies
     * @return array<int, array<string, mixed>>
     */
    private function uniqueStudies(array $studies): array
    {
        return collect($studies)
            ->unique(fn (array $study) => ($study['cart_item_id'] ?? 'study').'|'.($study['test_id'] ?? '').'|'.($study['position'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<int, array<string, mixed>>
     */
    private function uniqueComponents(array $components): array
    {
        return collect($components)
            ->unique(fn (array $component) => ($component['package_test_id'] ?? '').'|'.($component['component_index'] ?? '').'|'.($component['raw_text'] ?? ''))
            ->values()
            ->all();
    }
}
