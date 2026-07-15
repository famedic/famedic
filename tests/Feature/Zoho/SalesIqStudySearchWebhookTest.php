<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\ZohoSalesIqEvent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Config::set('services.zoho.salesiq.webhook_secret', 'test-zoho-webhook-secret');
    Config::set('services.openai.key', 'test-openai-key');
    Config::set('services.openai.model', 'gpt-4o-mini');
    Config::set('services.openai.timeout', 10);
});

function zohoStudySearchHeaders(string $secret = 'test-zoho-webhook-secret'): array
{
    return [
        'X-Famedic-Zoho-Secret' => $secret,
        'Accept' => 'application/json',
    ];
}

function createStudySearchTest(array $overrides = []): LaboratoryTest
{
    return LaboratoryTest::factory()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Perfil de lípidos',
        'other_name' => 'Colesterol y triglicéridos',
        'elements' => 'Colesterol HDL, LDL, VLDL, triglicéridos',
        'common_use' => 'Evaluación de grasas en sangre',
        'gda_id' => 'LIPIDOS-OLAB-1',
        'famedic_price_cents' => 45000,
        'public_price_cents' => 55000,
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create([
            'name' => 'Química clínica',
        ])->id,
    ], $overrides));
}

function createStudySearchStore(array $overrides = []): LaboratoryStore
{
    return LaboratoryStore::create(array_merge([
        'name' => 'Sucursal Centro',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Ciudad de México',
        'address' => 'Av. Reforma 123',
        'weekly_hours' => '9-18',
        'saturday_hours' => '9-14',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com/olab-centro',
    ], $overrides));
}

test('zoho study search rejects missing secret', function () {
    $this->postJson(route('webhooks.zoho.salesiq.study-search'), [
        'query' => 'colesterol',
    ])->assertUnauthorized()
        ->assertJson(['ok' => false]);

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho study search rejects invalid secret', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => 'colesterol'],
        zohoStudySearchHeaders('wrong-secret'),
    )->assertUnauthorized();

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho study search accepts valid secret', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH',
        'gda_id' => 'BH-OLAB-1',
    ]);

    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'Biometria hematica',
            'brand' => 'olab',
            'visitor_id' => 'zoho-test',
            'conversation_id' => 'conv-test',
            'page' => '/laboratory/olab/laboratory-tests',
            'environment' => 'beta',
        ],
        zohoStudySearchHeaders(),
    )->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonStructure(['bot_message', 'stores']);
});

test('zoho study search requires query', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['brand' => 'olab'],
        zohoStudySearchHeaders(),
    )->assertUnprocessable();

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho study search accepts unknown brand and searches all laboratories', function () {
    Http::fake();

    createStudySearchTest([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Glucosa Olab',
        'other_name' => 'Glucosa marca olab',
        'gda_id' => 'GLU-OLAB-UNIQ',
    ]);

    createStudySearchTest([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'name' => 'Glucosa Swisslab',
        'other_name' => 'Glucosa marca swiss',
        'gda_id' => 'GLU-SWISS-UNIQ',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create([
            'name' => 'Química swiss',
        ])->id,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'Glucosa marca',
            'brand' => 'unknown',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()->assertJsonPath('source', 'catalog');

    $brands = collect($response->json('results'))->pluck('brand')->unique()->sort()->values()->all();
    expect($brands)->toContain('olab')
        ->and($brands)->toContain('swisslab');
});

test('zoho study search returns catalog matches with enriched fields', function () {
    Http::fake();

    $test = createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH Completa',
        'gda_id' => 'BH-DIRECT-1',
        'famedic_price_cents' => 45000,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'Biometria',
            'brand' => 'olab',
            'visitor_id' => 'v-1',
            'conversation_id' => 'c-1',
            'environment' => 'beta',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('source', 'catalog')
        ->assertJsonPath('results.0.id', $test->id)
        ->assertJsonPath('results.0.search_code', 'BH-DIRECT-1')
        ->assertJsonPath('results.0.price_cents', 45000)
        ->assertJsonPath('results.0.price_formatted', '$450.00 MXN')
        ->assertJsonPath('results.0.brand', 'olab')
        ->assertJsonPath('results.0.brand_label', 'Olab');

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Biometria hematica')
        ->and($botMessage)->toContain('Código: BH-DIRECT-1')
        ->and($botMessage)->toContain('Precio Famedic: $450.00 MXN')
        ->and($botMessage)->toContain('Laboratorio: Olab')
        ->and($botMessage)->toContain('Puedes copiar el código o nombre del estudio');

    Http::assertNothingSent();

    $event = ZohoSalesIqEvent::query()->first();
    expect($event->payload['source'] ?? null)->toBe('catalog')
        ->and($event->payload['brand'] ?? null)->toBe('olab');
});

test('zoho study search matches query via other_name abbreviation', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Examen general de orina',
        'other_name' => 'EGO',
        'gda_id' => 'EGO-GDA-99',
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => 'EGO', 'brand' => 'olab'],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('results.0.name', 'Examen general de orina')
        ->assertJsonPath('results.0.search_code', 'EGO-GDA-99');
});

test('zoho study search prefers gda_id over other_name as search_code', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Perfil hepatico',
        'other_name' => 'HEP',
        'gda_id' => 'HEP-GDA-123',
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => 'HEP-GDA-123', 'brand' => 'olab'],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('results.0.search_code', 'HEP-GDA-123');
});

test('zoho study search does not invent price when famedic price is unavailable', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Estudio sin precio',
        'other_name' => 'SIN-PRECIO-UNIQ',
        'gda_id' => 'SIN-PRECIO-GDA',
        'famedic_price_cents' => 0,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => 'SIN-PRECIO-UNIQ', 'brand' => 'olab'],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('results.0.price_cents', null)
        ->assertJsonPath('results.0.price_formatted', 'Precio no disponible para esta opción');

    expect((string) $response->json('bot_message'))
        ->toContain('Precio no disponible para esta opción');
});

test('zoho study search returns stores when state is provided', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH-STORE-TEST',
        'gda_id' => 'BH-STORE-1',
    ]);

    createStudySearchStore([
        'name' => 'Olab Polanco',
        'state' => 'Ciudad de México',
        'address' => 'Av. Reforma 123, Col. Polanco',
    ]);

    createStudySearchStore([
        'name' => 'Olab Roma',
        'state' => 'Ciudad de México',
        'address' => 'Calle Ámsterdam 45, Col. Roma',
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'BH-STORE-TEST',
            'brand' => 'olab',
            'state' => 'Ciudad de México',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('stores.0.name', 'Olab Polanco')
        ->assertJsonPath('stores.0.state', 'Ciudad de México')
        ->assertJsonPath('stores.0.address', 'Av. Reforma 123, Col. Polanco')
        ->assertJsonPath('stores_count', 2)
        ->assertJsonPath('brand', 'olab')
        ->assertJsonPath('state', 'Ciudad de México');

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Código: BH-STORE-1')
        ->and($botMessage)->toContain('Precio Famedic:')
        ->and($botMessage)->toContain('Laboratorio: Olab')
        ->and($botMessage)->toContain('Sucursales:')
        ->and($botMessage)->toContain('Olab cuenta con 2 sucursales disponibles en Ciudad de México.')
        ->and($botMessage)->toContain('Puedes elegir la sucursal al continuar tu compra en Famedic.')
        ->and($botMessage)->toContain('Estado: Ciudad de México')
        ->and($botMessage)->not->toContain('Av. Reforma 123')
        ->and($botMessage)->not->toContain('Calle Ámsterdam 45')
        ->and($botMessage)->not->toContain('Olab Polanco —');

    $event = ZohoSalesIqEvent::query()->first();
    expect($event->payload['state'] ?? null)->toBe('Ciudad de México')
        ->and($event->payload['brand'] ?? null)->toBe('olab')
        ->and($event->payload['store_count'] ?? null)->toBe(2);
});

test('zoho study search reports missing stores without blocking study results', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH-NO-STORE',
        'gda_id' => 'BH-NOSTORE-1',
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'BH-NO-STORE',
            'brand' => 'olab',
            'state' => 'Nuevo León',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('results.0.name', 'Biometria hematica')
        ->assertJsonPath('stores', [])
        ->assertJsonPath('stores_count', 0)
        ->assertJsonPath('brand', 'olab')
        ->assertJsonPath('state', 'Nuevo León');

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Código: BH-NOSTORE-1')
        ->and($botMessage)->toContain('Precio Famedic:')
        ->and($botMessage)->toContain('Laboratorio: Olab')
        ->and($botMessage)->toContain('Sucursales:')
        ->and($botMessage)->toContain('No encontré sucursales de Olab disponibles en Nuevo León en este momento.');
});

test('zoho study search bot_message asks for state when stores state is missing', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH-NO-STATE',
        'gda_id' => 'BH-NOSTATE-1',
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'BH-NO-STATE',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('stores', [])
        ->assertJsonPath('stores_count', 0)
        ->assertJsonPath('brand', 'olab')
        ->assertJsonPath('state', null);

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Código: BH-NOSTATE-1')
        ->and($botMessage)->toContain('Laboratorio: Olab')
        ->and($botMessage)->toContain('Sucursales:')
        ->and($botMessage)->toContain('Olab cuenta con sucursales disponibles. Para mostrar disponibilidad por estado, indícame dónde te encuentras.')
        ->and($botMessage)->not->toContain('Av.');
});

test('zoho study search stores_count reflects total when more than response limit', function () {
    Http::fake();

    createStudySearchTest([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'name' => 'Biometria hematica swiss',
        'other_name' => 'BH-SWISS-COUNT',
        'gda_id' => 'BH-SWISS-COUNT-1',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create([
            'name' => 'Química swiss count',
        ])->id,
    ]);

    for ($i = 1; $i <= 8; $i++) {
        createStudySearchStore([
            'name' => "Swisslab Sucursal {$i}",
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'state' => 'Nuevo León',
            'address' => "Calle Ejemplo {$i}, Monterrey",
        ]);
    }

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'BH-SWISS-COUNT',
            'brand' => 'swisslab',
            'state' => 'Nuevo León',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('stores_count', 8)
        ->assertJsonPath('brand', 'swisslab')
        ->assertJsonPath('state', 'Nuevo León');

    expect(count($response->json('stores')))->toBe(5);

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Swisslab cuenta con 8 sucursales disponibles en Nuevo León.')
        ->and($botMessage)->not->toContain('Calle Ejemplo')
        ->and($botMessage)->not->toContain('Monterrey');

    $event = ZohoSalesIqEvent::query()->first();
    expect($event->payload['store_count'] ?? null)->toBe(8);
});

test('zoho study search respects brand filter', function () {
    Http::fake();

    createStudySearchTest([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Glucosa en sangre',
        'other_name' => 'Azucar olab',
        'gda_id' => 'GLU-OLAB',
    ]);

    createStudySearchTest([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'name' => 'Glucosa en sangre',
        'other_name' => 'Azucar swiss',
        'gda_id' => 'GLU-SWISS',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create([
            'name' => 'Química clínica swiss',
        ])->id,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'Azucar olab',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()->assertJsonPath('source', 'catalog');

    $brands = collect($response->json('results'))->pluck('brand')->unique()->values()->all();
    expect($brands)->toBe(['olab']);
});

test('zoho study search uses openai when no clear catalog match', function () {
    $candidate = createStudySearchTest([
        'name' => 'Perfil de lípidos',
        'other_name' => null,
        'elements' => 'Colesterol HDL LDL VLDL trigliceridos',
        'common_use' => 'Evaluacion de grasas',
        'gda_id' => 'LIP-OPENAI-1',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'selected_ids' => [$candidate->id],
                            'confidence' => 'medium',
                            'reason' => 'Relacionado con colesterol',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'colesterol bueno',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('source', 'openai_assisted')
        ->assertJsonPath('results.0.id', $candidate->id)
        ->assertJsonMissingPath('reason');

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Perfil de lípidos')
        ->and($botMessage)->toContain('LIP-OPENAI-1')
        ->and($botMessage)->toContain('Precio Famedic:');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
});

test('zoho study search returns no_results when openai fails without 500', function () {
    createStudySearchTest([
        'name' => 'Hemoglobina glucosilada',
        'other_name' => null,
        'elements' => 'azucar control glucosa cronico',
        'gda_id' => 'HBA1C-1',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'azucar cronico synonym',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'source' => 'no_results',
            'results' => [],
            'handoff_recommended' => true,
            'bot_message' => 'No pude confirmar una coincidencia segura en este momento. Te recomiendo hablar con Atención a Clientes.',
        ]);
});

test('zoho study search broad query returns safe bot_message with max three results', function () {
    Http::fake();

    createStudySearchTest(['name' => 'Examen general de orina', 'gda_id' => 'ORINA-1']);
    createStudySearchTest(['name' => 'Orina completa', 'gda_id' => 'ORINA-2', 'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Urologia'])->id]);
    createStudySearchTest(['name' => 'Urocultivo en orina', 'gda_id' => 'ORINA-3', 'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Urologia B'])->id]);
    createStudySearchTest(['name' => 'Sedimento de orina', 'gda_id' => 'ORINA-4', 'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Urologia C'])->id]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => 'orina', 'brand' => 'olab'],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()->assertJsonPath('source', 'catalog');

    expect(count($response->json('results')))->toBeLessThanOrEqual(3);

    $botMessage = (string) $response->json('bot_message');
    expect($botMessage)->toContain('Encontré varias opciones relacionadas con tu búsqueda')
        ->and($botMessage)->toContain('escribir un nombre más específico')
        ->and($botMessage)->not->toContain('4.');
});

test('zoho study search bot_message for unmatched query recommends handoff', function () {
    Http::fake();

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'xyzzy-estudio-inexistente-999',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'source' => 'no_results',
            'handoff_recommended' => true,
            'bot_message' => 'No encontré una coincidencia segura. Te recomiendo hablar con Atención a Clientes para evitar sugerirte un estudio incorrecto.',
        ]);

    Http::assertNothingSent();
});

test('zoho study search sanitizes payload and never stores secrets', function () {
    Http::fake();

    createStudySearchTest([
        'name' => 'Examen general de orina',
        'other_name' => 'EGO',
        'gda_id' => 'EGO-1',
    ]);

    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'EGO',
            'brand' => 'olab',
            'state' => 'Ciudad de México',
            'visitor_id' => 'v-safe',
            'password' => 'should-not-persist',
            'token' => 'secret-token',
            'otp' => '123456',
            'raw_response' => ['x' => 1],
        ],
        zohoStudySearchHeaders(),
    )->assertOk();

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->payload['brand'] ?? null)->toBe('olab')
        ->and($event->payload['state'] ?? null)->toBe('Ciudad de México')
        ->and($event->payload)->not->toHaveKey('password')
        ->and($event->payload)->not->toHaveKey('token')
        ->and($event->payload)->not->toHaveKey('otp')
        ->and($event->payload)->not->toHaveKey('raw_response')
        ->and(json_encode($event->payload))->not->toContain('should-not-persist')
        ->and(json_encode($event->payload))->not->toContain('secret-token');
});

test('zoho study search does not break existing webhook endpoints', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.events'),
        ['event_type' => 'bot_intent', 'intent' => 'help_payment'],
        zohoStudySearchHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $this->postJson(
        route('webhooks.zoho.salesiq.handoff'),
        ['visitor_id' => 'v1', 'intent' => 'talk_to_human'],
        zohoStudySearchHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $this->postJson(
        route('webhooks.zoho.salesiq.conversation-closed'),
        ['visitor_id' => 'v1', 'resolution' => 'resolved'],
        zohoStudySearchHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    expect(ZohoSalesIqEvent::count())->toBe(3);
});
