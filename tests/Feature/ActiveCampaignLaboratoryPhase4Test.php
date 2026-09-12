<?php

use App\Models\ActiveCampaignDispatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.fields.lab.url_finalizar_compra' => 101,
        'services.activecampaign.fields.lab.paciente_lab' => 102,
        'services.activecampaign.fields.lab.sucursal_lab' => 103,
        'services.activecampaign.fields.lab.google_maps_lab' => 104,
        'services.activecampaign.fields.lab.direccion_lab' => 105,
        'services.activecampaign.fields.lab.fecha_cita_lab' => 106,
        'services.activecampaign.fields.lab.horario_cita_lab' => 107,
        'services.activecampaign.fields.lab.folio_famedic' => 108,
        'services.activecampaign.fields.lab.gda_consecutivo' => 109,
        'services.activecampaign.fields.lab.mapa_sucursales_labs' => 110,
        'services.activecampaign.fields.lab.toma_de_muestra_lab' => 111,
        'services.activecampaign.fields.lab.resultados_lab' => 112,
    ]);
});

function phase4AcFields(array $overrides = []): array
{
    $fields = [
        ['id' => '101', 'title' => 'URL Finalizar Compra', 'type' => 'text', 'perstag' => '%URL_FINALIZAR_COMPRA%'],
        ['id' => '102', 'title' => 'Paciente Laboratorio', 'type' => 'text', 'perstag' => '%PACIENTE_LAB%'],
        ['id' => '103', 'title' => 'Sucursal Laboratorio', 'type' => 'text', 'perstag' => '%SUCURSAL_LAB%'],
        ['id' => '104', 'title' => 'Google Maps Laboratorio', 'type' => 'text', 'perstag' => '%GOOGLE_MAPS_LAB%'],
        ['id' => '105', 'title' => 'Direccion Laboratorio', 'type' => 'textarea', 'perstag' => '%DIRECCION_LAB%'],
        ['id' => '106', 'title' => 'Fecha Cita Laboratorio', 'type' => 'date', 'perstag' => '%FECHA_CITA_LAB%'],
        ['id' => '107', 'title' => 'Horario Cita Laboratorio', 'type' => 'text', 'perstag' => '%HORARIO_CITA_LAB%'],
        ['id' => '108', 'title' => 'Folio FAMEDIC', 'type' => 'text', 'perstag' => '%FOLIO_FAMEDIC%'],
        ['id' => '109', 'title' => 'Consecutivo GDA', 'type' => 'text', 'perstag' => '%GDA_CONSECUTIVO%'],
        ['id' => '110', 'title' => 'Mapa Sucursales Laboratorio', 'type' => 'text', 'perstag' => '%MAPA_SUCURSALES_LABS%'],
        ['id' => '111', 'title' => 'Toma de Muestra', 'type' => 'text', 'perstag' => '%TOMA_DE_MUESTRA_LAB%'],
        ['id' => '112', 'title' => 'Resultados Disponibles', 'type' => 'text', 'perstag' => '%RESULTADOS_LAB%'],
    ];

    foreach ($overrides as $id => $override) {
        foreach ($fields as &$field) {
            if ($field['id'] === (string) $id) {
                $field = array_merge($field, $override);
            }
        }
    }

    return $fields;
}

function phase4FakeFields(array $fields): void
{
    Http::fake([
        'https://ac.test/api/3/fields*' => Http::response([
            'fields' => $fields,
            'meta' => ['total' => count($fields)],
        ], 200),
    ]);
}

it('verifies all configured lab fields successfully', function () {
    phase4FakeFields(phase4AcFields());

    $exit = Artisan::call('activecampaign:verify-lab-fields');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('url_finalizar_compra')
        ->and($output)->toContain('OK')
        ->and($output)->toContain('%PACIENTE_LAB%');
});

it('reports missing config, field not found, title mismatch and type warning', function () {
    config([
        'services.activecampaign.fields.lab.google_maps_lab' => null,
        'services.activecampaign.fields.lab.folio_famedic' => 999999,
    ]);
    phase4FakeFields(phase4AcFields([
        102 => ['title' => 'Nombre equivocado'],
        106 => ['type' => 'text'],
    ]));

    $exit = Artisan::call('activecampaign:verify-lab-fields');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('MISSING_CONFIG')
        ->and($output)->toContain('FIELD_NOT_FOUND')
        ->and($output)->toContain('TITLE_MISMATCH')
        ->and($output)->toContain('TYPE_WARNING');
});

it('returns safe json for API 401 and does not expose token', function () {
    config(['services.activecampaign.token' => 'super-secret-token']);
    Http::fake([
        'https://ac.test/api/3/fields*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $exit = Artisan::call('activecampaign:verify-lab-fields', ['--json' => true]);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($exit)->toBe(2)
        ->and($json['api_error'])->toBeTrue()
        ->and($json['http_status'])->toBe(401)
        ->and($output)->not->toContain('super-secret-token');
});

it('returns API_ERROR for ActiveCampaign 500', function () {
    Http::fake([
        'https://ac.test/api/3/fields*' => Http::response(['message' => 'server error'], 500),
    ]);

    $exit = Artisan::call('activecampaign:verify-lab-fields');
    $output = Artisan::output();

    expect($exit)->toBe(2)
        ->and($output)->toContain('error temporal');
});

it('emits valid json when requested', function () {
    phase4FakeFields(phase4AcFields());

    $exit = Artisan::call('activecampaign:verify-lab-fields', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($json)->toBeArray()
        ->and($json['ok'])->toBe(12)
        ->and($json['fields'])->toHaveCount(12);
});

it('verifies configured tags and reports invalid ids', function () {
    config([
        'services.activecampaign.tags.cart.abandoned' => 20,
        'services.activecampaign.tags.cart.appointment_pending' => 'Cita pendiente',
        'services.activecampaign.tag_laboratory_purchase_completed' => 18,
        'services.activecampaign.tag_lab_sample_collected' => 999,
        'services.activecampaign.tag_lab_results_available' => 33,
    ]);

    Http::fake([
        'https://ac.test/api/3/fields*' => Http::response([
            'fields' => phase4AcFields(),
            'meta' => ['total' => 12],
        ], 200),
        'https://ac.test/api/3/tags*' => Http::response([
            'tags' => [
                ['id' => '20', 'tag' => 'Carrito abandonado'],
                ['id' => '21', 'tag' => 'Cita pendiente'],
                ['id' => '18', 'tag' => 'Compra laboratorio completada'],
                ['id' => '33', 'tag' => 'Resultados disponibles'],
            ],
            'meta' => ['total' => 4],
        ], 200),
    ]);

    $exit = Artisan::call('activecampaign:verify-lab-fields', ['--tags' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('services.activecampaign.tag_lab_sample_collected')
        ->and($output)->toContain('TAG_NOT_FOUND')
        ->and($output)->toContain('Carrito abandonado');
});

it('lists dispatches using status filter', function () {
    $failed = ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => 10,
        'customer_id' => 20,
        'email' => 'failed@example.com',
        'idempotency_key' => 'failed-key',
        'status' => ActiveCampaignDispatch::STATUS_FAILED,
        'attempts' => 2,
        'last_error' => 'AC 500',
        'payload' => [
            'operation' => 'lab_custom_fields',
            'cart_id' => 10,
            'custom_fields' => ['url_finalizar_compra' => 'https://famedic.test/laboratory/checkout/resume/token'],
        ],
    ]);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => 11,
        'email' => 'synced@example.com',
        'idempotency_key' => 'synced-key',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'payload' => ['operation' => 'tag_add'],
    ]);

    $exit = Artisan::call('activecampaign:dispatches', ['--status' => 'failed']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain((string) $failed->id)
        ->and($output)->toContain('failed')
        ->and($output)->toContain('url_finalizar_compra')
        ->and($output)->not->toContain('synced-key');
});

it('redacts resume tokens in dispatch detail', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => 10,
        'email' => 'patient@example.com',
        'idempotency_key' => 'detail-key',
        'status' => ActiveCampaignDispatch::STATUS_FAILED,
        'payload' => [
            'operation' => 'lab_custom_fields',
            'custom_fields' => [
                'url_finalizar_compra' => 'https://famedic.test/laboratory/checkout/resume/raw-token-123',
                'paciente_lab' => 'Juan Perez Lopez',
                'direccion_lab' => 'Calle privada 123',
            ],
        ],
    ]);

    $exit = Artisan::call('activecampaign:dispatches', ['--id' => $dispatch->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('/laboratory/checkout/resume/[REDACTED]')
        ->and($output)->toContain('J*** P*** L***')
        ->and($output)->toContain('[REDACTED]')
        ->and($output)->not->toContain('raw-token-123')
        ->and($output)->not->toContain('Calle privada 123');
});
