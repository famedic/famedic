<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryRequirements\StudyRequirementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPackageRequirementCapabilities();
});

it('resolves chequeo general plus to one laboratorio requirement with component provenance', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'CHEQUEO GENERAL PLUS',
        [
            'Biometría hemática',
            'Química sanguínea de 6 elementos: urea, creatinina, ácido úrico, glucosa, colesterol, triglicéridos',
            'Examen general de orina',
        ],
    ));

    $laboratorio = packageRequirementBySlug($resolved, 'laboratorio');

    expect($resolved->confidence)->toBe(StudyRequirementResolver::CONFIDENCE_CATEGORY)
        ->and($resolved->groups)->toHaveCount(1)
        ->and($resolved->groups[0]->source)->toBe(StudyRequirementResolver::SOURCE_PACKAGE_COMPONENT)
        ->and($laboratorio->evidence['components'])->toHaveCount(3)
        ->and(collect($laboratorio->evidence['components'])->pluck('raw_text')->all())->toBe([
            'Biometría hemática',
            'Química sanguínea de 6 elementos: urea, creatinina, ácido úrico, glucosa, colesterol, triglicéridos',
            'Examen general de orina',
        ]);
});

it('resolves perfil mujer menor 40 with laboratorio ultrasound alternative and papanicolaou', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PERFIL MUJER MENOR 40',
        [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'ECO DE MAMA BILATERAL',
            'EXAMEN GENERAL DE ORINA',
            'GLUCOSA EN SANGRE',
            'COLESTEROL TOTAL',
            'TRIGLICÉRIDOS',
            'PAPANICOLAOU (CITOLOGÍA VAGINAL)',
        ],
    ));

    expect(packageRequirementSlugs($resolved))->toBe([
        ['laboratorio'],
        ['papanicolaou'],
        ['ultrasonido_convencional', 'ultrasonido_especial'],
    ])
        ->and(packageGroupBySlugs($resolved, ['ultrasonido_convencional', 'ultrasonido_especial'])->operator)
        ->toBe(StudyRequirementResolver::OPERATOR_ANY)
        ->and(packageRequirementBySlug($resolved, 'laboratorio')->evidence['components'])->toHaveCount(5)
        ->and(packageRequirementBySlug($resolved, 'papanicolaou')->evidence['components'][0]['raw_text'])
        ->toBe('PAPANICOLAOU (CITOLOGÍA VAGINAL)');
});

it('resolves perfil mujer mayor 40 with mastografia and not only rayos x', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PERFIL MUJER MAYOR 40',
        [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'RX MAMOGRAFÍA BILATERAL',
            'EXAMEN GENERAL DE ORINA',
            'GLUCOSA EN SANGRE',
            'COLESTEROL TOTAL',
            'TRIGLICÉRIDOS',
            'PAPANICOLAOU (CITOLOGÍ VAGINAL)',
        ],
    ));

    expect(packageRequirementSlugs($resolved))->toBe([
        ['laboratorio'],
        ['mastografia'],
        ['papanicolaou'],
    ])
        ->and(packageRequirementBySlug($resolved, 'mastografia')->evidence['components'][0]['raw_text'])
        ->toBe('RX MAMOGRAFÍA BILATERAL')
        ->and(collect($resolved->groups)->flatMap(fn ($group) => $group->requirements)->pluck('capabilitySlug')->all())
        ->not->toContain('rayos_x');
});

it('resolves doppler inside a package to ultrasonido especial', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PAQUETE DOPPLER',
        ['ECO DE ABDOMEN SUPERIOR DOPPLER'],
    ));

    expect(packageRequirementSlugs($resolved))->toBe([
        ['ultrasonido_especial'],
    ]);
});

it('resolves mammography inside a package to mastografia', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PAQUETE MASTOGRAFIA',
        ['RX MAMOGRAFIA BILATERAL'],
    ));

    expect(packageRequirementSlugs($resolved))->toBe([
        ['mastografia'],
    ]);
});

it('keeps unknown package components visible without hiding known requirements', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PAQUETE PARCIALMENTE DESCONOCIDO',
        [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'COMPONENTE OPERATIVO NO IDENTIFICABLE',
        ],
    ));

    expect($resolved->confidence)->toBe(StudyRequirementResolver::CONFIDENCE_UNKNOWN)
        ->and($resolved->unresolvedReasons)->toBe(['insufficient_evidence'])
        ->and(packageRequirementSlugs($resolved))->toBe([['laboratorio']])
        ->and($resolved->evidence['components'][1]['raw_text'])->toBe('COMPONENTE OPERATIVO NO IDENTIFICABLE')
        ->and($resolved->evidence['components'][1]['unresolved_reason'])->toBe('insufficient_evidence');
});

it('deduplicates repeated laboratorio components while preserving provenance', function (): void {
    $resolved = app(StudyRequirementResolver::class)->resolve(packageRequirementTest(
        'PAQUETE LABORATORIO REPETIDO',
        [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'QUÍMICA SANGUÍNEA DE 3 ELEMENTOS',
            'EXAMEN GENERAL DE ORINA',
            'GLUCOSA EN SANGRE',
        ],
    ));

    $laboratorio = packageRequirementBySlug($resolved, 'laboratorio');

    expect($resolved->groups)->toHaveCount(1)
        ->and($laboratorio->capabilitySlug)->toBe('laboratorio')
        ->and($laboratorio->evidence['components'])->toHaveCount(4);
});

function seedPackageRequirementCapabilities(): void
{
    collect([
        'laboratorio' => 'Laboratorio',
        'rayos_x' => 'Rayos X',
        'mastografia' => 'Mastografia',
        'ultrasonido_convencional' => 'Ultrasonido Convencional',
        'ultrasonido_especial' => 'Ultrasonido Especial',
        'papanicolaou' => 'Papanicolaou',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->create([
        'slug' => $slug,
        'name' => $name,
        'is_active' => true,
    ]));
}

function packageRequirementTest(string $name, array $featureList): LaboratoryTest
{
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => 'Chequeos y Paquetes']);

    return LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_id' => fake()->unique()->numerify('128###'),
        'name' => $name,
        'feature_list' => $featureList,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function packageRequirementSlugs($resolved): array
{
    return collect($resolved->groups)
        ->map(fn ($group) => collect($group->requirements)->pluck('capabilitySlug')->sort()->values()->all())
        ->sortBy(fn (array $slugs) => implode('|', $slugs))
        ->values()
        ->all();
}

function packageRequirementBySlug($resolved, string $slug)
{
    return collect($resolved->groups)
        ->flatMap(fn ($group) => $group->requirements)
        ->firstOrFail(fn ($requirement) => $requirement->capabilitySlug === $slug);
}

function packageGroupBySlugs($resolved, array $slugs)
{
    sort($slugs);

    return collect($resolved->groups)
        ->firstOrFail(function ($group) use ($slugs) {
            $groupSlugs = collect($group->requirements)->pluck('capabilitySlug')->sort()->values()->all();

            return $groupSlugs === $slugs;
        });
}
