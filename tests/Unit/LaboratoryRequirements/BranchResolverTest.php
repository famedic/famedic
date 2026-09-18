<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Services\LaboratoryRequirements\BranchResolver;
use App\Services\LaboratoryRequirements\CartRequirementAggregator;
use App\Services\LaboratoryRequirements\StudyRequirementResolver;
use App\Support\LaboratoryRequirements\CartRequirements;
use App\Support\LaboratoryRequirements\GeoPoint;
use App\Support\LaboratoryRequirements\ResolvedRequirement;
use App\Support\LaboratoryRequirements\ResolvedRequirementGroup;
use App\Support\LaboratoryRequirements\ResolvedStudyRequirements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedBranchResolverCapabilities();
});

it('filters by brand before matching capabilities and keeps partial branches incompatible', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('Cart', [
            branchGroup('all:laboratorio', 'all', ['laboratorio']),
            branchGroup('all:tomografia', 'all', ['tomografia']),
            branchGroup('all:papanicolaou', 'all', ['papanicolaou']),
            branchGroup('any:ultra', 'any', ['ultrasonido_convencional', 'ultrasonido_especial']),
        ]),
    ]);

    $compatible = branchStore('Swisslab Completa', LaboratoryBrand::SWISSLAB, ['laboratorio', 'ultrasonido_especial', 'papanicolaou', 'tomografia']);
    $partial = branchStore('Swisslab Parcial', LaboratoryBrand::SWISSLAB, ['laboratorio', 'ultrasonido_convencional']);
    branchStore('Olab Completa', LaboratoryBrand::OLAB, ['laboratorio', 'ultrasonido_especial', 'papanicolaou', 'tomografia']);

    $resolution = app(BranchResolver::class)->resolve($requirements, date: CarbonImmutable::parse('2026-09-17 10:00:00', 'America/Mexico_City'));

    expect($resolution->branches)->toHaveCount(2)
        ->and($resolution->branches[0]->branch->id)->toBe($compatible->id)
        ->and($resolution->branches[0]->isCompatible)->toBeTrue()
        ->and($resolution->branches[0]->missingRequirements)->toBe([])
        ->and($resolution->branches[1]->branch->id)->toBe($partial->id)
        ->and($resolution->branches[1]->isCompatible)->toBeFalse()
        ->and($resolution->branches[1]->missingRequirements)->toBe(['papanicolaou', 'tomografia']);
});

it('satisfies any groups with one or both alternatives', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('Eco', [
            branchGroup('any:ultra', 'any', ['ultrasonido_convencional', 'ultrasonido_especial']),
        ]),
    ]);

    branchStore('Con convencional', LaboratoryBrand::SWISSLAB, ['ultrasonido_convencional']);
    branchStore('Con ambas', LaboratoryBrand::SWISSLAB, ['ultrasonido_convencional', 'ultrasonido_especial']);
    branchStore('Sin ultra', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $resolution = app(BranchResolver::class)->resolve($requirements);

    expect($resolution->branches[0]->isCompatible)->toBeTrue()
        ->and($resolution->branches[1]->isCompatible)->toBeTrue()
        ->and($resolution->branches[2]->isCompatible)->toBeFalse()
        ->and($resolution->branches[2]->groups[0]->missingCapabilities)
        ->toBe(['ultrasonido_convencional', 'ultrasonido_especial']);
});

it('marks unknown cart requirements as not fully compatible while preserving matched capabilities', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('Parcial', [
            branchGroup('all:laboratorio', 'all', ['laboratorio']),
        ], unresolved: ['insufficient_evidence']),
    ]);

    branchStore('Lab', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $resolution = app(BranchResolver::class)->resolve($requirements);

    expect($resolution->branches[0]->isCompatible)->toBeFalse()
        ->and($resolution->branches[0]->matchLevel)->toBe(BranchResolver::MATCH_UNKNOWN)
        ->and($resolution->branches[0]->matchedRequirements)->toBe(['laboratorio'])
        ->and($resolution->branches[0]->reasons)->toContain('requirements_unknown');
});

it('returns no candidates for empty requirements and unknown brands', function (): void {
    $empty = new CartRequirements(null, [], [], [], StudyRequirementResolver::CONFIDENCE_UNKNOWN, false, ['no_requirements']);
    $unknownBrand = branchCartRequirements(null, [branchResolvedStudy('Sin brand', [branchGroup('all:laboratorio', 'all', ['laboratorio'])])]);

    expect(app(BranchResolver::class)->resolve($empty)->reasons)->toBe(['no_requirements'])
        ->and(app(BranchResolver::class)->resolve($unknownBrand)->reasons)->toBe(['brand_unknown']);
});

it('calculates distance only when a location is provided and orders compatible branches by distance', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('Lab', [branchGroup('all:laboratorio', 'all', ['laboratorio'])]),
    ]);

    branchStore('Cerca', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3902300', longitude: '-99.1740300');
    branchStore('Lejos', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3650650', longitude: '-99.1781010');

    $withoutLocation = app(BranchResolver::class)->resolve($requirements);
    $withLocation = app(BranchResolver::class)->resolve($requirements, new GeoPoint(19.3902300, -99.1740300));

    expect($withoutLocation->branches[0]->distanceKm)->toBeNull()
        ->and($withLocation->branches[0]->branch->name)->toBe('Cerca')
        ->and($withLocation->branches[0]->distanceKm)->toBe(0.0)
        ->and($withLocation->branches[1]->distanceKm)->toBeGreaterThan(0);
});

it('exposes hours without claiming appointment availability', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('Lab', [branchGroup('all:laboratorio', 'all', ['laboratorio'])]),
    ]);

    branchStore('Horario', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $resolution = app(BranchResolver::class)->resolve(
        $requirements,
        date: CarbonImmutable::parse('2026-09-17 10:00:00', 'America/Mexico_City'),
    );

    expect($resolution->branches[0]->hours->isOpenNow)->toBeTrue()
        ->and($resolution->branches[0]->hours->opensOnRequestedDate)->toBeTrue()
        ->and($resolution->branches[0]->hours->hoursSummary)->toBe('07:00-15:00')
        ->and($resolution->branches[0]->hours->appointmentAvailability)->toBe('unknown');
});

it('resolves a real mixed cart contract produced by phase three', function (): void {
    $requirements = branchCartRequirements('swisslab', [
        branchResolvedStudy('ANGIOTAC', [branchGroup('all:tomografia', 'all', ['tomografia'])]),
        branchResolvedStudy('ECO MAMA', [branchGroup('any:ultra', 'any', ['ultrasonido_convencional', 'ultrasonido_especial'])]),
        branchResolvedStudy('PERFIL MUJER MENOR 40', [
            branchGroup('all:laboratorio', 'all', ['laboratorio']),
            branchGroup('all:papanicolaou', 'all', ['papanicolaou']),
            branchGroup('any:ultra', 'any', ['ultrasonido_convencional', 'ultrasonido_especial']),
        ]),
    ]);

    branchStore('Compatible', LaboratoryBrand::SWISSLAB, ['laboratorio', 'tomografia', 'papanicolaou', 'ultrasonido_especial']);
    branchStore('Incompleta', LaboratoryBrand::SWISSLAB, ['laboratorio', 'ultrasonido_especial']);

    $resolution = app(BranchResolver::class)->resolve($requirements);

    expect($resolution->branches[0]->isCompatible)->toBeTrue()
        ->and($resolution->branches[0]->matchedRequirements)->toBe(['laboratorio', 'papanicolaou', 'tomografia', 'ultrasonido_especial'])
        ->and($resolution->branches[1]->missingRequirements)->toBe(['papanicolaou', 'tomografia']);
});

it('keeps multibrand resolutions separate', function (): void {
    $olab = branchCartRequirements('olab', [
        branchResolvedStudy('OLAB Lab', [branchGroup('all:laboratorio', 'all', ['laboratorio'])]),
    ]);
    $swiss = branchCartRequirements('swisslab', [
        branchResolvedStudy('Swiss Tomo', [branchGroup('all:tomografia', 'all', ['tomografia'])]),
    ]);
    $requirements = new CartRequirements(
        cartId: null,
        studies: [...$olab->studies, ...$swiss->studies],
        groups: [...$olab->groups, ...$swiss->groups],
        requirements: [...$olab->requirements, ...$swiss->requirements],
        confidence: StudyRequirementResolver::CONFIDENCE_CATEGORY,
        isResolvable: true,
        brands: [...$olab->brands, ...$swiss->brands],
    );

    branchStore('OLAB Store', LaboratoryBrand::OLAB, ['laboratorio']);
    branchStore('Swiss Store', LaboratoryBrand::SWISSLAB, ['tomografia']);

    $resolution = app(BranchResolver::class)->resolve($requirements);

    expect(array_keys($resolution->brands))->toBe(['olab', 'swisslab'])
        ->and($resolution->brands['olab'][0]->branch->name)->toBe('OLAB Store')
        ->and($resolution->brands['swisslab'][0]->branch->name)->toBe('Swiss Store');
});

function seedBranchResolverCapabilities(): void
{
    collect(['laboratorio', 'tomografia', 'papanicolaou', 'ultrasonido_convencional', 'ultrasonido_especial', 'mastografia'])
        ->each(fn (string $slug) => LaboratoryCapability::query()->create([
            'slug' => $slug,
            'name' => str_replace('_', ' ', $slug),
            'is_active' => true,
        ]));
}

function branchStore(
    string $name,
    LaboratoryBrand $brand,
    array $capabilitySlugs,
    ?string $latitude = '19.3902300',
    ?string $longitude = '-99.1740300',
): LaboratoryStore {
    $store = LaboratoryStore::query()->create([
        'name' => $name,
        'brand' => $brand->value,
        'state' => 'Ciudad de Mexico',
        'address' => $name.' address',
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.test',
        'is_active' => true,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    $store->capabilities()->attach(
        LaboratoryCapability::query()->whereIn('slug', $capabilitySlugs)->pluck('id')->all(),
    );

    foreach (range(1, 7) as $day) {
        LaboratoryStoreHour::query()->create([
            'laboratory_store_id' => $store->id,
            'day_of_week' => $day,
            'opens_at' => $day === 7 ? null : '07:00:00',
            'closes_at' => $day === 7 ? null : '15:00:00',
            'is_closed' => $day === 7,
            'raw_text' => $day === 7 ? 'Cerrado' : '07:00-15:00',
        ]);
    }

    return $store;
}

function branchCartRequirements(?string $brand, array $resolvedStudies): CartRequirements
{
    $entries = collect($resolvedStudies)->map(function (ResolvedStudyRequirements $resolved) use ($brand) {
        return [
            'resolved' => $resolved,
            'study' => [
                'test_id' => $resolved->testId,
                'gda_id' => $resolved->gdaId,
                'name' => $resolved->testName,
                'brand' => $brand,
                'quantity' => 1,
            ],
        ];
    });

    return app(CartRequirementAggregator::class)->aggregateResolved($entries);
}

function branchResolvedStudy(string $name, array $groups, array $unresolved = []): ResolvedStudyRequirements
{
    return new ResolvedStudyRequirements(
        testId: fake()->unique()->numberBetween(1000, 9999),
        gdaId: fake()->unique()->numerify('8#####'),
        categoryId: null,
        testName: $name,
        confidence: $unresolved === [] ? StudyRequirementResolver::CONFIDENCE_CATEGORY : StudyRequirementResolver::CONFIDENCE_UNKNOWN,
        groups: $groups,
        unresolvedReasons: $unresolved,
    );
}

function branchGroup(string $key, string $operator, array $slugs): ResolvedRequirementGroup
{
    return new ResolvedRequirementGroup(
        groupKey: $key,
        operator: $operator,
        required: true,
        source: StudyRequirementResolver::SOURCE_CATEGORY,
        confidence: StudyRequirementResolver::CONFIDENCE_CATEGORY,
        requirements: collect($slugs)->map(fn (string $slug) => new ResolvedRequirement(
            capabilitySlug: $slug,
            capabilityId: null,
            label: $slug,
            required: true,
            source: StudyRequirementResolver::SOURCE_CATEGORY,
            confidence: StudyRequirementResolver::CONFIDENCE_CATEGORY,
        ))->all(),
    );
}
