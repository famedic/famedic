<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStudyRequirementGroup;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryRequirements\StudyRequirementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    collect([
        'laboratorio' => 'Laboratorio',
        'rayos_x' => 'Rayos X',
        'mastografia' => 'Mastografia',
        'ultrasonido_convencional' => 'Ultrasonido Convencional',
        'ultrasonido_especial' => 'Ultrasonido Especial',
        'resonancia_magnetica' => 'Resonancia Magnetica',
        'tomografia' => 'Tomografia',
        'audiometria' => 'Audiometria',
        'electrocardio' => 'Electrocardio',
        'espirometria' => 'Espirometria',
        'papanicolaou' => 'Papanicolaou',
        'densitometria' => 'Densitometria',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->create([
        'slug' => $slug,
        'name' => $name,
        'is_active' => true,
    ]));
});

it('resolves safe and specific laboratory study requirements', function (
    string $name,
    string $categoryName,
    array $expectedSlugs,
    string $expectedSource,
    string $expectedConfidence,
    string $expectedOperator = StudyRequirementResolver::OPERATOR_ALL,
): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(laboratoryRequirementsTest($name, $categoryName));

    expect($resolved->confidence)->toBe($expectedConfidence)
        ->and($resolved->unresolvedReasons)->toBe([])
        ->and($resolved->groups)->toHaveCount(1)
        ->and($resolved->groups[0]->source)->toBe($expectedSource)
        ->and($resolved->groups[0]->operator)->toBe($expectedOperator)
        ->and(collect($resolved->groups[0]->requirements)->pluck('capabilitySlug')->all())->toBe($expectedSlugs);
})->with([
    'RX ANTEBRAZO' => ['RX ANTEBRAZO', 'Rayos X', ['rayos_x'], StudyRequirementResolver::SOURCE_CATEGORY, StudyRequirementResolver::CONFIDENCE_CATEGORY],
    'RX MAMOGRAFIA BILATERAL' => ['RX MAMOGRAFIA BILATERAL', 'Rayos X', ['mastografia'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
    'ECO DE MAMA BILATERAL' => ['ECO DE MAMA BILATERAL', 'Ultrasonido', ['ultrasonido_convencional', 'ultrasonido_especial'], StudyRequirementResolver::SOURCE_CATEGORY, StudyRequirementResolver::CONFIDENCE_CATEGORY, StudyRequirementResolver::OPERATOR_ANY],
    'ECO DE ABDOMEN SUPERIOR DOPPLER' => ['ECO DE ABDOMEN SUPERIOR DOPPLER', 'Ultrasonido', ['ultrasonido_especial'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
    'RM CRANEO' => ['RM CRANEO', 'Resonancia', ['resonancia_magnetica'], StudyRequirementResolver::SOURCE_CATEGORY, StudyRequirementResolver::CONFIDENCE_CATEGORY],
    'ANGIOTAC DE ABDOMEN' => ['ANGIOTAC DE ABDOMEN', 'Tomografía', ['tomografia'], StudyRequirementResolver::SOURCE_CATEGORY, StudyRequirementResolver::CONFIDENCE_CATEGORY],
    'AUDIOMETRIA' => ['AUDIOMETRIA', 'Especiales', ['audiometria'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
    'ELECTROCARDIOGRAMA' => ['ELECTROCARDIOGRAMA', 'Especiales', ['electrocardio'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
    'ESPIROMETRIA' => ['ESPIROMETRIA', 'Especiales', ['espirometria'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
    'DENSITOMETRIA' => ['DENSITOMETRIA', 'Especiales', ['densitometria'], StudyRequirementResolver::SOURCE_RULE, StudyRequirementResolver::CONFIDENCE_MAPPED],
]);

it('does not degrade mammography to only rayos x', function (): void {
    $resolved = app(StudyRequirementResolver::class)
        ->resolve(laboratoryRequirementsTest('RX MAMOGRAFIA BILATERAL', 'Rayos X'));

    $slugs = collect($resolved->groups[0]->requirements)->pluck('capabilitySlug')->all();

    expect($slugs)->toBe(['mastografia'])
        ->and($slugs)->not->toContain('rayos_x');
});

it('returns unknown for service copy and non mappable generic categories', function (
    string $name,
    string $categoryName,
    array $expectedReasons,
): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(laboratoryRequirementsTest($name, $categoryName));

    expect($resolved->confidence)->toBe(StudyRequirementResolver::CONFIDENCE_UNKNOWN)
        ->and($resolved->groups)->toBe([])
        ->and($resolved->unresolvedReasons)->toBe($expectedReasons);
})->with([
    'portable xray service' => ['SERVICIO DE RADIOGRAFIAS PORTATILES', 'Tomografía', ['service_or_copy']],
    'plate copy' => ['COPIA DE PLACAS', 'Resonancia', ['service_or_copy']],
    'generic especiales' => ['ESTUDIO ESPECIAL SIN REGLA', 'Especiales', ['category_not_mappable']],
]);

it('lets manual requirements override generic category resolution', function (): void {
    $test = laboratoryRequirementsTest('RX ANTEBRAZO', 'Rayos X');
    $capability = LaboratoryCapability::query()->where('slug', 'mastografia')->firstOrFail();

    $group = LaboratoryStudyRequirementGroup::query()->create([
        'laboratory_test_id' => $test->id,
        'group_key' => 'manual:mastografia',
        'operator' => StudyRequirementResolver::OPERATOR_ALL,
        'source' => StudyRequirementResolver::SOURCE_MANUAL,
        'confidence' => StudyRequirementResolver::CONFIDENCE_MAPPED,
        'evidence' => ['reason' => 'admin_reviewed'],
    ]);

    $group->requirements()->create([
        'laboratory_capability_id' => $capability->id,
        'capability_slug' => $capability->slug,
        'requirement_type' => 'capability',
        'source' => StudyRequirementResolver::SOURCE_MANUAL,
        'confidence' => StudyRequirementResolver::CONFIDENCE_MAPPED,
        'evidence' => ['reason' => 'admin_reviewed'],
    ]);

    $resolved = app(StudyRequirementResolver::class)->resolve($test);

    expect($resolved->confidence)->toBe(StudyRequirementResolver::CONFIDENCE_MAPPED)
        ->and($resolved->groups[0]->source)->toBe(StudyRequirementResolver::SOURCE_MANUAL)
        ->and($resolved->groups[0]->requirements[0]->capabilitySlug)->toBe('mastografia');
});

function laboratoryRequirementsTest(string $name, string $categoryName): LaboratoryTest
{
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => $categoryName]);

    return LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_id' => fake()->unique()->numerify('######'),
        'name' => $name,
        'laboratory_test_category_id' => $category->id,
    ]);
}
