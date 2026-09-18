<?php

namespace App\Services\LaboratoryRequirements;

use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStudyRequirementGroup;
use App\Models\LaboratoryTest;
use App\Services\LaboratoryStores\Gda\GdaCapabilityCatalog;
use App\Support\LaboratoryRequirements\ResolvedRequirement;
use App\Support\LaboratoryRequirements\ResolvedRequirementGroup;
use App\Support\LaboratoryRequirements\ResolvedStudyRequirements;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StudyRequirementResolver
{
    public const OPERATOR_ALL = 'all';

    public const OPERATOR_ANY = 'any';

    public const SOURCE_EXACT = 'exact';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PACKAGE_COMPONENT = 'package_component';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_CATEGORY = 'category';

    public const SOURCE_APPROXIMATE = 'approximate';

    public const SOURCE_UNKNOWN = 'unknown';

    public const CONFIDENCE_MAPPED = 'MAPPED';

    public const CONFIDENCE_CATEGORY = 'CATEGORY';

    public const CONFIDENCE_UNKNOWN = 'UNKNOWN';

    private const REQUIREMENT_TYPE_CAPABILITY = 'capability';

    private const CATEGORY_CAPABILITY_MAP = [
        'SANGUINEO' => 'laboratorio',
        'URIANALISIS' => 'laboratorio',
        'NASOFARINGEO' => 'laboratorio',
        'COPRO' => 'laboratorio',
        'CULTIVO' => 'laboratorio',
        'RAYOS X' => 'rayos_x',
        'RESONANCIA' => 'resonancia_magnetica',
        'TOMOGRAFIA' => 'tomografia',
    ];

    public function resolve(LaboratoryTest $test): ResolvedStudyRequirements
    {
        $test->loadMissing('laboratoryTestCategory');

        if ($manual = $this->manualRequirements($test)) {
            return $manual;
        }

        $normalizedName = $this->normalize($test->name.' '.$test->other_name);
        $category = $this->normalize((string) $test->laboratoryTestCategory?->name);

        if ($this->isServiceOrCopy($normalizedName)) {
            return $this->unknown($test, ['service_or_copy'], [
                'matched_text' => $test->name,
                'category' => $test->laboratoryTestCategory?->name,
            ]);
        }

        if ($rule = $this->resolveByRule($test, $normalizedName)) {
            return $rule;
        }

        if ($category === 'CHEQUEOS Y PAQUETES' && is_array($test->feature_list) && $test->feature_list !== []) {
            return app(PackageRequirementParser::class)->resolve($test, $this);
        }

        if ($category === 'ULTRASONIDO') {
            return $this->resolved($test, [
                $this->group(
                    'category:ultrasonido:any',
                    self::OPERATOR_ANY,
                    self::SOURCE_CATEGORY,
                    self::CONFIDENCE_CATEGORY,
                    ['ultrasonido_convencional', 'ultrasonido_especial'],
                    [
                        'category' => $test->laboratoryTestCategory?->name,
                        'rule' => 'ultrasonido_without_doppler_is_alternative',
                    ],
                ),
            ], self::CONFIDENCE_CATEGORY, [
                'category' => $test->laboratoryTestCategory?->name,
                'note' => 'Non-Doppler ultrasound is intentionally represented as an alternative.',
            ]);
        }

        if ($slug = self::CATEGORY_CAPABILITY_MAP[$category] ?? null) {
            return $this->resolved($test, [
                $this->singleCapabilityGroup(
                    'category:'.$category,
                    self::SOURCE_CATEGORY,
                    self::CONFIDENCE_CATEGORY,
                    $slug,
                    ['category' => $test->laboratoryTestCategory?->name],
                ),
            ], self::CONFIDENCE_CATEGORY, [
                'category' => $test->laboratoryTestCategory?->name,
            ]);
        }

        return $this->unknown($test, $this->unknownReasons($test, $category), [
            'category' => $test->laboratoryTestCategory?->name,
            'feature_list_format' => is_array($test->feature_list) ? 'array' : gettype($test->feature_list),
            'feature_list_count' => is_array($test->feature_list) ? count($test->feature_list) : null,
        ]);
    }

    private function resolveByRule(LaboratoryTest $test, string $normalizedName): ?ResolvedStudyRequirements
    {
        $rules = [
            'mastografia' => ['/(^| )MASTOGRAFIA( |$)/', '/(^| )MAMOGRAFIA( |$)/'],
            'ultrasonido_especial' => ['/(^| )DOPPLER( |$)/'],
            'audiometria' => ['/(^| )AUDIOMETRIA( |$)/'],
            'electrocardio' => ['/(^| )ELECTROCARDIOGRAMA( |$)/', '/(^| )ECG( |$)/'],
            'espirometria' => ['/(^| )ESPIROMETRIA( |$)/'],
            'papanicolaou' => ['/(^| )PAPANICOLAOU( |$)/', '/(^| )PAPANICOLAU( |$)/', '/(^| )PAP( |$)/'],
            'densitometria' => ['/(^| )DENSITOMETRIA( |$)/'],
        ];

        foreach ($rules as $slug => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalizedName) === 1) {
                    return $this->resolved($test, [
                        $this->singleCapabilityGroup(
                            'rule:'.$slug,
                            self::SOURCE_RULE,
                            self::CONFIDENCE_MAPPED,
                            $slug,
                            ['matched_text' => $test->name, 'pattern' => $pattern],
                        ),
                    ], self::CONFIDENCE_MAPPED, [
                        'matched_text' => $test->name,
                        'matched_slug' => $slug,
                    ]);
                }
            }
        }

        return null;
    }

    private function manualRequirements(LaboratoryTest $test): ?ResolvedStudyRequirements
    {
        if (! Schema::hasTable('laboratory_study_requirement_groups')) {
            return null;
        }

        $groups = $test->studyRequirementGroups()
            ->where('source', self::SOURCE_MANUAL)
            ->where('is_active', true)
            ->with(['requirements' => fn ($query) => $query
                ->where('is_active', true)
                ->with('laboratoryCapability')])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($groups->isEmpty()) {
            return null;
        }

        return $this->resolved(
            $test,
            $groups->map(fn (LaboratoryStudyRequirementGroup $group) => $this->manualGroup($group))->all(),
            self::CONFIDENCE_MAPPED,
            ['source' => self::SOURCE_MANUAL],
        );
    }

    private function manualGroup(LaboratoryStudyRequirementGroup $group): ResolvedRequirementGroup
    {
        return new ResolvedRequirementGroup(
            groupKey: $group->group_key,
            operator: $group->operator,
            required: $group->is_required,
            source: $group->source,
            confidence: $group->confidence,
            requirements: $group->requirements
                ->map(fn ($requirement) => new ResolvedRequirement(
                    capabilitySlug: $requirement->capability_slug ?? $requirement->laboratoryCapability?->slug,
                    capabilityId: $requirement->laboratory_capability_id,
                    label: $requirement->laboratoryCapability?->name,
                    required: $requirement->is_required,
                    source: $requirement->source,
                    confidence: $requirement->confidence,
                    evidence: $requirement->evidence ?? [],
                    notes: $requirement->notes,
                ))
                ->all(),
            evidence: $group->evidence ?? [],
        );
    }

    private function singleCapabilityGroup(
        string $groupKey,
        string $source,
        string $confidence,
        string $slug,
        array $evidence,
    ): ResolvedRequirementGroup {
        return $this->group($groupKey, self::OPERATOR_ALL, $source, $confidence, [$slug], $evidence);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function group(
        string $groupKey,
        string $operator,
        string $source,
        string $confidence,
        array $slugs,
        array $evidence,
    ): ResolvedRequirementGroup {
        $capabilities = $this->capabilities($slugs);

        return new ResolvedRequirementGroup(
            groupKey: $groupKey,
            operator: $operator,
            required: true,
            source: $source,
            confidence: $confidence,
            requirements: array_map(
                fn (string $slug) => new ResolvedRequirement(
                    capabilitySlug: $slug,
                    capabilityId: $capabilities[$slug]['id'],
                    label: $capabilities[$slug]['name'],
                    required: true,
                    source: $source,
                    confidence: $confidence,
                    evidence: array_merge($evidence, [
                        'capability_slug' => $slug,
                        'requirement_type' => self::REQUIREMENT_TYPE_CAPABILITY,
                    ]),
                ),
                $slugs,
            ),
            evidence: $evidence,
        );
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<string, array{id: ?int, name: ?string}>
     */
    private function capabilities(array $slugs): array
    {
        $catalog = collect(GdaCapabilityCatalog::MAP)
            ->mapWithKeys(fn (array $capability) => [$capability['slug'] => [
                'id' => null,
                'name' => $capability['name'],
            ]]);

        if (Schema::hasTable('laboratory_capabilities')) {
            LaboratoryCapability::query()
                ->whereIn('slug', $slugs)
                ->get(['id', 'slug', 'name'])
                ->each(function (LaboratoryCapability $capability) use ($catalog): void {
                    $catalog[$capability->slug] = [
                        'id' => $capability->id,
                        'name' => $capability->name,
                    ];
                });
        }

        return collect($slugs)
            ->mapWithKeys(fn (string $slug) => [$slug => $catalog[$slug] ?? ['id' => null, 'name' => null]])
            ->all();
    }

    /**
     * @param  array<int, ResolvedRequirementGroup>  $groups
     */
    private function resolved(LaboratoryTest $test, array $groups, string $confidence, array $evidence): ResolvedStudyRequirements
    {
        return new ResolvedStudyRequirements(
            testId: $test->id,
            gdaId: $test->gda_id,
            categoryId: $test->laboratory_test_category_id,
            testName: $test->name,
            confidence: $confidence,
            groups: $groups,
            evidence: $evidence,
        );
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function unknown(LaboratoryTest $test, array $reasons, array $evidence): ResolvedStudyRequirements
    {
        return new ResolvedStudyRequirements(
            testId: $test->id,
            gdaId: $test->gda_id,
            categoryId: $test->laboratory_test_category_id,
            testName: $test->name,
            confidence: self::CONFIDENCE_UNKNOWN,
            unresolvedReasons: array_values(array_unique($reasons)),
            evidence: $evidence,
        );
    }

    /**
     * @return array<int, string>
     */
    private function unknownReasons(LaboratoryTest $test, string $category): array
    {
        if ($category === 'CHEQUEOS Y PAQUETES' && is_array($test->feature_list) && $test->feature_list !== []) {
            return ['category_not_mappable', 'insufficient_evidence'];
        }

        if (in_array($category, ['ESPECIALES', 'CUTANEA', 'CHEQUEOS Y PAQUETES'], true)) {
            return ['category_not_mappable'];
        }

        return ['insufficient_evidence'];
    }

    private function isServiceOrCopy(string $normalizedName): bool
    {
        return str_contains($normalizedName, 'SERVICIO DE RADIOGRAFIAS')
            || str_contains($normalizedName, 'COPIA DE PLACAS');
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii($value);
        $upper = mb_strtoupper($ascii);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $upper));
    }
}
