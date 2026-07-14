<?php

use App\Enums\LaboratoryBrand;
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
        ->assertJson(['ok' => true]);
});

test('zoho study search requires query', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['brand' => 'olab'],
        zohoStudySearchHeaders(),
    )->assertUnprocessable();

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho study search rejects empty query', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => '   '],
        zohoStudySearchHeaders(),
    )->assertUnprocessable();
});

test('zoho study search validates max query length', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        ['query' => str_repeat('a', 121)],
        zohoStudySearchHeaders(),
    )->assertUnprocessable();
});

test('zoho study search returns catalog matches without calling openai', function () {
    Http::fake();

    $test = createStudySearchTest([
        'name' => 'Biometria hematica',
        'other_name' => 'BH Completa',
        'gda_id' => 'BH-DIRECT-1',
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
        ->assertJsonPath('ok', true)
        ->assertJsonPath('source', 'catalog')
        ->assertJsonPath('handoff_recommended', false)
        ->assertJsonPath('results.0.id', $test->id)
        ->assertJsonPath('results.0.name', 'Biometria hematica')
        ->assertJsonPath('results.0.brand', 'olab')
        ->assertJsonPath('results.0.price_cents', 45000)
        ->assertJsonPath(
            'bot_message',
            "Encontré estas opciones:\n1. Biometria hematica\n\nSi no estás seguro, puedo canalizarte con Atención a Clientes."
        );

    Http::assertNothingSent();

    $event = ZohoSalesIqEvent::query()->first();
    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe('study_search')
        ->and($event->intent)->toBe('help_study_search')
        ->and($event->last_event)->toBe('search_no_results')
        ->and($event->payload['source'] ?? null)->toBe('catalog')
        ->and($event->payload['result_count'] ?? null)->toBe(1)
        ->and($event->payload['result_ids'] ?? null)->toBe([$test->id]);
});

test('zoho study search respects brand filter', function () {
    Http::fake();

    createStudySearchTest([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Glucosa en sangre',
        'other_name' => 'Azúcar',
        'gda_id' => 'GLU-OLAB',
    ]);

    createStudySearchTest([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'name' => 'Glucosa en sangre',
        'other_name' => 'Azúcar',
        'gda_id' => 'GLU-SWISS',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create([
            'name' => 'Química clínica swiss',
        ])->id,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'Glucosa en sangre',
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
            'visitor_id' => 'zoho-test',
            'conversation_id' => 'zoho-test-conversation',
            'page' => '/laboratory/olab/laboratory-tests',
            'environment' => 'beta',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('source', 'openai_assisted')
        ->assertJsonPath('handoff_recommended', false)
        ->assertJsonPath('results.0.id', $candidate->id)
        ->assertJsonPath(
            'bot_message',
            "Encontré estas opciones:\n1. Perfil de lípidos\n\nSi no estás seguro, puedo canalizarte con Atención a Clientes."
        )
        ->assertJsonMissingPath('reason');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    $event = ZohoSalesIqEvent::query()->first();
    expect($event->event_type)->toBe('study_search')
        ->and($event->payload['source'] ?? null)->toBe('openai_assisted')
        ->and($event->payload['query'] ?? null)->toBe('colesterol bueno')
        ->and($event->payload)->not->toHaveKey('password');
});

test('zoho study search never returns invented or out-of-candidate ids', function () {
    $candidate = createStudySearchTest([
        'name' => 'Perfil tiroideo',
        'other_name' => null,
        'elements' => 'TSH T3 T4 tiroides',
        'gda_id' => 'TIR-1',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'selected_ids' => [$candidate->id, 999999, 42],
                            'confidence' => 'low',
                            'reason' => 'intento inventar ids',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'tiroides raro synonym',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()->assertJsonPath('source', 'openai_assisted');

    $ids = collect($response->json('results'))->pluck('id')->all();
    expect($ids)->toBe([$candidate->id])
        ->and($ids)->not->toContain(999999)
        ->and($ids)->not->toContain(42);
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

    expect(ZohoSalesIqEvent::query()->where('event_type', 'study_search')->exists())->toBeTrue();
});

test('zoho study search bot_message lists at most three study names', function () {
    Http::fake();

    createStudySearchTest(['name' => 'Estudio uno', 'other_name' => 'shared-match', 'gda_id' => 'BOT-1']);
    createStudySearchTest([
        'name' => 'Estudio dos',
        'other_name' => 'shared-match',
        'gda_id' => 'BOT-2',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Cat B'])->id,
    ]);
    createStudySearchTest([
        'name' => 'Estudio tres',
        'other_name' => 'shared-match',
        'gda_id' => 'BOT-3',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Cat C'])->id,
    ]);
    createStudySearchTest([
        'name' => 'Estudio cuatro',
        'other_name' => 'shared-match',
        'gda_id' => 'BOT-4',
        'laboratory_test_category_id' => LaboratoryTestCategory::factory()->create(['name' => 'Cat D'])->id,
    ]);

    $response = $this->postJson(
        route('webhooks.zoho.salesiq.study-search'),
        [
            'query' => 'shared-match',
            'brand' => 'olab',
        ],
        zohoStudySearchHeaders(),
    );

    $response->assertOk()->assertJsonPath('source', 'catalog');

    $botMessage = (string) $response->json('bot_message');

    expect($botMessage)->toStartWith("Encontré estas opciones:\n")
        ->and($botMessage)->toContain('1. ')
        ->and($botMessage)->toContain('2. ')
        ->and($botMessage)->toContain('3. ')
        ->and($botMessage)->not->toContain('4. ')
        ->and($botMessage)->toContain('Si no estás seguro, puedo canalizarte con Atención a Clientes.')
        ->and($botMessage)->not->toContain('password')
        ->and($botMessage)->not->toContain('token')
        ->and($botMessage)->not->toContain('selected_ids');
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
            'results' => [],
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
            'visitor_id' => 'v-safe',
            'password' => 'should-not-persist',
            'token' => 'secret-token',
            'otp' => '123456',
            'raw_response' => ['x' => 1],
        ],
        zohoStudySearchHeaders(),
    )->assertOk();

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->payload)->not->toHaveKey('password')
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
