<?php

namespace App\Services\LaboratoryRequirements;

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Support\LaboratoryRequirements\ParsedPackageComponent;
use App\Support\LaboratoryRequirements\ResolvedRequirement;
use App\Support\LaboratoryRequirements\ResolvedRequirementGroup;
use App\Support\LaboratoryRequirements\ResolvedStudyRequirements;
use Illuminate\Support\Str;

class PackageRequirementParser
{
    /**
     * @return array<int, ParsedPackageComponent>
     */
    public function parseComponents(mixed $featureList): array
    {
        return collect($this->featureListItems($featureList))
            ->values()
            ->map(fn (string $rawText, int $index) => $this->parseComponent($rawText, $index))
            ->all();
    }

    public function resolve(LaboratoryTest $package, StudyRequirementResolver $resolver): ResolvedStudyRequirements
    {
        $components = $this->parseComponents($package->feature_list);
        $memoized = [];
        $groups = [];
        $unresolvedReasons = [];

        foreach ($components as $component) {
            if ($component->unresolvedReason !== null || $component->inferredCategory === null) {
                $unresolvedReasons[] = $component->unresolvedReason ?? 'insufficient_evidence';

                continue;
            }

            $memoKey = $component->normalizedText.'|'.$component->inferredCategory;
            $componentResolution = $memoized[$memoKey] ??= $resolver->resolve(
                $this->componentTest($package, $component),
            );

            if ($componentResolution->confidence === StudyRequirementResolver::CONFIDENCE_UNKNOWN) {
                array_push($unresolvedReasons, ...$componentResolution->unresolvedReasons);

                continue;
            }

            foreach ($componentResolution->groups as $group) {
                $groupKey = $this->aggregateGroupKey($group);
                $groups[$groupKey] = $this->mergeGroup(
                    $groups[$groupKey] ?? null,
                    $group,
                    $package,
                    $component,
                );
            }
        }

        return new ResolvedStudyRequirements(
            testId: $package->id,
            gdaId: $package->gda_id,
            categoryId: $package->laboratory_test_category_id,
            testName: $package->name,
            confidence: $this->packageConfidence(array_values($groups), $unresolvedReasons),
            groups: array_values($groups),
            unresolvedReasons: array_values(array_unique($unresolvedReasons)),
            evidence: [
                'source' => StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT,
                'package_name' => $package->name,
                'feature_list_count' => count($components),
                'components' => array_map(fn (ParsedPackageComponent $component) => $component->toArray(), $components),
            ],
        );
    }

    private function parseComponent(string $rawText, int $index): ParsedPackageComponent
    {
        $normalizedText = $this->normalize($rawText);
        $category = $this->inferCategory($normalizedText);

        if ($normalizedText === '') {
            return new ParsedPackageComponent(
                rawText: $rawText,
                normalizedText: $normalizedText,
                index: $index,
                confidence: StudyRequirementResolver::CONFIDENCE_UNKNOWN,
                evidence: ['reason' => 'empty_component'],
                unresolvedReason: 'insufficient_evidence',
            );
        }

        if ($category === null) {
            return new ParsedPackageComponent(
                rawText: $rawText,
                normalizedText: $normalizedText,
                index: $index,
                confidence: StudyRequirementResolver::CONFIDENCE_UNKNOWN,
                evidence: ['reason' => 'no_deterministic_component_category'],
                unresolvedReason: 'insufficient_evidence',
            );
        }

        return new ParsedPackageComponent(
            rawText: $rawText,
            normalizedText: $normalizedText,
            index: $index,
            confidence: StudyRequirementResolver::CONFIDENCE_MAPPED,
            evidence: ['inferred_category' => $category],
            inferredCategory: $category,
        );
    }

    /**
     * @return array<int, string>
     */
    private function featureListItems(mixed $featureList): array
    {
        if (! is_array($featureList)) {
            return [];
        }

        return collect($featureList)
            ->flatMap(function (mixed $item): array {
                if (! is_string($item)) {
                    return [];
                }

                $trimmed = trim($item);

                if ($trimmed === '') {
                    return [];
                }

                return preg_split('/\R+/', $trimmed) ?: [];
            })
            ->map(fn (string $item) => trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $item)))
            ->filter()
            ->values()
            ->all();
    }

    private function inferCategory(string $normalizedText): ?string
    {
        if (preg_match('/(^| )(RX|MAMOGRAFIA|MASTOGRAFIA)( |$)/', $normalizedText) === 1) {
            return 'Rayos X';
        }

        if (preg_match('/(^| )(ECO|ULTRASONIDO)( |$)/', $normalizedText) === 1) {
            return 'Ultrasonido';
        }

        if (preg_match('/(^| )(PAPANICOLAOU|PAPANICOLAU|PAP)( |$)/', $normalizedText) === 1) {
            return 'Especiales';
        }

        if (str_contains($normalizedText, 'EXAMEN GENERAL DE ORINA')
            || str_contains($normalizedText, 'EGO')
            || str_contains($normalizedText, 'ORINA')) {
            return 'Urianálisis';
        }

        foreach ([
            'BIOMETRIA HEMATICA',
            'QUIMICA SANGUINEA',
            'GLUCOSA EN SANGRE',
            'COLESTEROL',
            'TRIGLICERIDOS',
            'HEMOGLOBINA GLICOLISADA',
            'GLICOHEMOGLOBINA',
            'PERFIL TIROIDEO',
            'ANTIGENO PROSTATICO',
        ] as $laboratoryPattern) {
            if (str_contains($normalizedText, $laboratoryPattern)) {
                return 'Sanguíneo';
            }
        }

        return null;
    }

    private function componentTest(LaboratoryTest $package, ParsedPackageComponent $component): LaboratoryTest
    {
        $test = new LaboratoryTest([
            'gda_id' => $package->gda_id.'#component-'.$component->index,
            'name' => $component->rawText,
            'laboratory_test_category_id' => null,
            'feature_list' => null,
        ]);

        $test->id = $package->id;
        $test->setRelation('laboratoryTestCategory', new LaboratoryTestCategory([
            'name' => $component->inferredCategory,
        ]));

        return $test;
    }

    private function mergeGroup(
        ?ResolvedRequirementGroup $current,
        ResolvedRequirementGroup $incoming,
        LaboratoryTest $package,
        ParsedPackageComponent $component,
    ): ResolvedRequirementGroup {
        $provenance = $this->componentProvenance($package, $component, $incoming);

        if ($current === null) {
            return new ResolvedRequirementGroup(
                groupKey: $this->aggregateGroupKey($incoming),
                operator: $incoming->operator,
                required: $incoming->required,
                source: StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT,
                confidence: $incoming->confidence,
                requirements: array_map(
                    fn (ResolvedRequirement $requirement) => $this->packageRequirement($requirement, [$provenance]),
                    $incoming->requirements,
                ),
                evidence: [
                    'source' => StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT,
                    'operator_from_component' => $incoming->operator,
                    'components' => [$provenance],
                ],
            );
        }

        return new ResolvedRequirementGroup(
            groupKey: $current->groupKey,
            operator: $current->operator,
            required: $current->required,
            source: $current->source,
            confidence: $this->highestConfidence($current->confidence, $incoming->confidence),
            requirements: $this->mergeRequirements($current->requirements, $incoming->requirements, $provenance),
            evidence: array_merge($current->evidence, [
                'components' => array_merge($current->evidence['components'] ?? [], [$provenance]),
            ]),
        );
    }

    /**
     * @param  array<int, ResolvedRequirement>  $current
     * @param  array<int, ResolvedRequirement>  $incoming
     * @return array<int, ResolvedRequirement>
     */
    private function mergeRequirements(array $current, array $incoming, array $provenance): array
    {
        $requirements = collect($current)
            ->keyBy(fn (ResolvedRequirement $requirement) => $requirement->capabilitySlug)
            ->all();

        foreach ($incoming as $requirement) {
            $existing = $requirements[$requirement->capabilitySlug] ?? null;
            $provenances = $existing?->evidence['components'] ?? [];
            $provenances[] = $provenance;

            $requirements[$requirement->capabilitySlug] = $this->packageRequirement(
                $existing ?? $requirement,
                $provenances,
            );
        }

        return array_values($requirements);
    }

    private function packageRequirement(ResolvedRequirement $requirement, array $provenances): ResolvedRequirement
    {
        return new ResolvedRequirement(
            capabilitySlug: $requirement->capabilitySlug,
            capabilityId: $requirement->capabilityId,
            label: $requirement->label,
            required: $requirement->required,
            source: StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT,
            confidence: $requirement->confidence,
            evidence: [
                'source' => StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT,
                'inner_source' => $requirement->source,
                'inner_confidence' => $requirement->confidence,
                'components' => $provenances,
            ],
            notes: $requirement->notes,
        );
    }

    private function componentProvenance(
        LaboratoryTest $package,
        ParsedPackageComponent $component,
        ResolvedRequirementGroup $incoming,
    ): array {
        return [
            'package_test_id' => $package->id,
            'package_gda_id' => $package->gda_id,
            'package_name' => $package->name,
            'component_index' => $component->index,
            'raw_text' => $component->rawText,
            'normalized_text' => $component->normalizedText,
            'inferred_category' => $component->inferredCategory,
            'component_group_key' => $incoming->groupKey,
            'component_source' => $incoming->source,
            'component_confidence' => $incoming->confidence,
        ];
    }

    private function aggregateGroupKey(ResolvedRequirementGroup $group): string
    {
        $slugs = collect($group->requirements)
            ->pluck('capabilitySlug')
            ->filter()
            ->sort()
            ->implode('|');

        return 'package_component:'.$group->operator.':'.$slugs;
    }

    /**
     * @param  array<int, ResolvedRequirementGroup>  $groups
     * @param  array<int, string>  $unresolvedReasons
     */
    private function packageConfidence(array $groups, array $unresolvedReasons): string
    {
        if ($unresolvedReasons !== [] || $groups === []) {
            return StudyRequirementResolver::CONFIDENCE_UNKNOWN;
        }

        $confidences = collect($groups)->pluck('confidence');

        if ($confidences->contains(StudyRequirementResolver::CONFIDENCE_CATEGORY)) {
            return StudyRequirementResolver::CONFIDENCE_CATEGORY;
        }

        return StudyRequirementResolver::CONFIDENCE_MAPPED;
    }

    private function highestConfidence(string $left, string $right): string
    {
        if ($left === StudyRequirementResolver::CONFIDENCE_UNKNOWN || $right === StudyRequirementResolver::CONFIDENCE_UNKNOWN) {
            return StudyRequirementResolver::CONFIDENCE_UNKNOWN;
        }

        if ($left === StudyRequirementResolver::CONFIDENCE_CATEGORY || $right === StudyRequirementResolver::CONFIDENCE_CATEGORY) {
            return StudyRequirementResolver::CONFIDENCE_CATEGORY;
        }

        return StudyRequirementResolver::CONFIDENCE_MAPPED;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii($value);
        $upper = mb_strtoupper($ascii);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $upper));
    }
}
