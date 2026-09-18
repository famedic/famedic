<?php

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryRequirements\CartRequirementAggregator;
use App\Services\LaboratoryRequirements\StudyRequirementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedCartRequirementCapabilities();
});

it('deduplicates identical laboratorio requirements while preserving two study sources', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('GLUCOSA EN SANGRE', 'Sanguíneo'),
        cartRequirementStudy('EXAMEN GENERAL DE ORINA', 'Urianálisis'),
    ]));

    expect($cart->requirements)->toHaveCount(1)
        ->and($cart->requirements[0]->capabilitySlug)->toBe('laboratorio')
        ->and($cart->requirements[0]->studies)->toHaveCount(2)
        ->and($cart->groups)->toHaveCount(1);
});

it('keeps different requirements as separate logical groups', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('ANGIOTAC DE ABDOMEN', 'Tomografía'),
        cartRequirementStudy('GLUCOSA EN SANGRE', 'Sanguíneo'),
    ]));

    expect(cartRequirementSlugs($cart))->toBe(['laboratorio', 'tomografia'])
        ->and($cart->groups)->toHaveCount(2);
});

it('preserves ultrasound alternatives inside one any group', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('ECO DE MAMA BILATERAL', 'Ultrasonido'),
    ]));

    $group = $cart->groups[0];

    expect($group->operator)->toBe(StudyRequirementResolver::OPERATOR_ANY)
        ->and(collect($group->requirements)->pluck('capabilitySlug')->sort()->values()->all())
        ->toBe(['ultrasonido_convencional', 'ultrasonido_especial']);
});

it('does not turn ultrasound alternative plus doppler into an accidental and', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('ECO DE MAMA BILATERAL', 'Ultrasonido'),
        cartRequirementStudy('ECO DE ABDOMEN SUPERIOR DOPPLER', 'Ultrasonido'),
    ]));

    expect($cart->groups)->toHaveCount(2)
        ->and(collect($cart->groups)->map(fn ($group) => [
            'operator' => $group->operator,
            'slugs' => collect($group->requirements)->pluck('capabilitySlug')->sort()->values()->all(),
        ])->sortBy(fn (array $group) => $group['operator'].implode('|', $group['slugs']))->values()->all())->toBe([
            [
                'operator' => StudyRequirementResolver::OPERATOR_ALL,
                'slugs' => ['ultrasonido_especial'],
            ],
            [
                'operator' => StudyRequirementResolver::OPERATOR_ANY,
                'slugs' => ['ultrasonido_convencional', 'ultrasonido_especial'],
            ],
        ]);
});

it('aggregates a mixed cart with angi otac eco mama and perfil mujer menor 40', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('ANGIOTAC DE ABDOMEN', 'Tomografía'),
        cartRequirementStudy('ECO DE MAMA BILATERAL', 'Ultrasonido'),
        cartRequirementPackage('PERFIL MUJER MENOR 40', [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'ECO DE MAMA BILATERAL',
            'EXAMEN GENERAL DE ORINA',
            'GLUCOSA EN SANGRE',
            'COLESTEROL TOTAL',
            'TRIGLICÉRIDOS',
            'PAPANICOLAOU (CITOLOGÍA VAGINAL)',
        ]),
    ]));

    expect(cartRequirementSlugs($cart))->toBe([
        'laboratorio',
        'papanicolaou',
        'tomografia',
        'ultrasonido_convencional',
        'ultrasonido_especial',
    ])
        ->and(cartRequirementBySlug($cart, 'tomografia')->studies[0]['name'])->toBe('ANGIOTAC DE ABDOMEN')
        ->and(cartRequirementBySlug($cart, 'laboratorio')->studies[0]['name'])->toBe('PERFIL MUJER MENOR 40')
        ->and(cartRequirementBySlug($cart, 'papanicolaou')->components[0]['raw_text'])->toBe('PAPANICOLAOU (CITOLOGÍA VAGINAL)')
        ->and(cartRequirementBySlug($cart, 'ultrasonido_convencional')->studies)->toHaveCount(2);
});

it('keeps known requirements and marks the cart unresolved for a partially unknown package', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementPackage('PAQUETE PARCIAL', [
            'BIOMETRÍA HEMÁTICA COMPLETA',
            'COMPONENTE OPERATIVO NO IDENTIFICABLE',
        ]),
    ]));

    expect($cart->isResolvable)->toBeFalse()
        ->and($cart->confidence)->toBe(StudyRequirementResolver::CONFIDENCE_UNKNOWN)
        ->and($cart->unresolvedReasons)->toBe(['insufficient_evidence'])
        ->and(cartRequirementSlugs($cart))->toBe(['laboratorio']);
});

it('does not duplicate requirements when the same study appears twice as cart items', function (): void {
    $customer = Customer::factory()->withRegularAccount()->create();
    $study = cartRequirementStudy('GLUCOSA EN SANGRE', 'Sanguíneo');

    $first = LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $study->id]);
    $second = LaboratoryCartItem::factory()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $study->id]);

    $cart = app(CartRequirementAggregator::class)->aggregateItems(collect([$first, $second]), (string) $customer->id);

    expect($cart->requirements)->toHaveCount(1)
        ->and($cart->requirements[0]->capabilitySlug)->toBe('laboratorio')
        ->and($cart->requirements[0]->studies)->toHaveCount(2)
        ->and($cart->evidence['quantity_semantics'])->toContain('provenance only');
});

it('groups multibrand cart evidence without silently losing brand boundaries', function (): void {
    $cart = app(CartRequirementAggregator::class)->aggregateStudies(collect([
        cartRequirementStudy('GLUCOSA EN SANGRE', 'Sanguíneo', LaboratoryBrand::OLAB),
        cartRequirementStudy('ANGIOTAC DE ABDOMEN', 'Tomografía', LaboratoryBrand::SWISSLAB),
    ]));

    expect(array_keys($cart->brands))->toBe(['olab', 'swisslab'])
        ->and($cart->brands['olab']['requirements'])->toBe(['laboratorio'])
        ->and($cart->brands['swisslab']['requirements'])->toBe(['tomografia']);
});

it('keeps overlapping alternatives as independent groups when merging would change meaning', function (): void {
    $resolvedA = resolvedCartStudyWithGroup('Study A', StudyRequirementResolver::OPERATOR_ANY, ['a', 'b']);
    $resolvedB = resolvedCartStudyWithGroup('Study B', StudyRequirementResolver::OPERATOR_ANY, ['b', 'c']);

    $cart = app(CartRequirementAggregator::class)->aggregateResolved(collect([$resolvedA, $resolvedB]));

    expect($cart->groups)->toHaveCount(2)
        ->and(collect($cart->groups)->map(fn ($group) => collect($group->requirements)->pluck('capabilitySlug')->sort()->values()->all())->all())
        ->toBe([
            ['a', 'b'],
            ['b', 'c'],
        ]);
});

function seedCartRequirementCapabilities(): void
{
    collect([
        'laboratorio' => 'Laboratorio',
        'tomografia' => 'Tomografia',
        'ultrasonido_convencional' => 'Ultrasonido Convencional',
        'ultrasonido_especial' => 'Ultrasonido Especial',
        'papanicolaou' => 'Papanicolaou',
        'a' => 'A',
        'b' => 'B',
        'c' => 'C',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->create([
        'slug' => $slug,
        'name' => $name,
        'is_active' => true,
    ]));
}

function cartRequirementStudy(
    string $name,
    string $categoryName,
    LaboratoryBrand $brand = LaboratoryBrand::OLAB,
): LaboratoryTest {
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => $categoryName]);

    return LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'gda_id' => fake()->unique()->numerify('7#####'),
        'name' => $name,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function cartRequirementPackage(string $name, array $featureList): LaboratoryTest
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

function cartRequirementSlugs($cart): array
{
    return collect($cart->requirements)
        ->pluck('capabilitySlug')
        ->sort()
        ->values()
        ->all();
}

function cartRequirementBySlug($cart, string $slug)
{
    return collect($cart->requirements)->firstOrFail(fn ($requirement) => $requirement->capabilitySlug === $slug);
}

function resolvedCartStudyWithGroup(string $name, string $operator, array $slugs)
{
    return new \App\Support\LaboratoryRequirements\ResolvedStudyRequirements(
        testId: fake()->numberBetween(1000, 9999),
        gdaId: fake()->unique()->numerify('9#####'),
        categoryId: null,
        testName: $name,
        confidence: StudyRequirementResolver::CONFIDENCE_MAPPED,
        groups: [
            new \App\Support\LaboratoryRequirements\ResolvedRequirementGroup(
                groupKey: $name,
                operator: $operator,
                required: true,
                source: StudyRequirementResolver::SOURCE_RULE,
                confidence: StudyRequirementResolver::CONFIDENCE_MAPPED,
                requirements: collect($slugs)
                    ->map(fn (string $slug) => new \App\Support\LaboratoryRequirements\ResolvedRequirement(
                        capabilitySlug: $slug,
                        capabilityId: null,
                        label: strtoupper($slug),
                        required: true,
                        source: StudyRequirementResolver::SOURCE_RULE,
                        confidence: StudyRequirementResolver::CONFIDENCE_MAPPED,
                    ))
                    ->all(),
            ),
        ],
    );
}
