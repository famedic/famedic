<?php

use App\Contracts\Otp\VonageSmsSendGateway;
use App\Enums\Otp\OtpMovementFlow;
use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;
use App\Models\Administrator;
use App\Models\AkubicaRegistrationIntent;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\OtpMovementEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeVonageSmsSendGateway;

use function Tests\Support\Otp\vonageSmsCollection;

function otpMonitorAdminUser(): User
{
    $user = User::factory()->create();
    $admin = Administrator::factory()->create(['user_id' => $user->id]);
    $permission = Permission::query()->firstOrCreate([
        'name' => 'otp-movements.monitor',
        'guard_name' => 'web',
    ], [
        'description' => 'Monitorear movimientos OTP de Akúbica',
    ]);
    $admin->givePermissionTo($permission);

    return $user->fresh();
}

function otpSmsDiagnosticAdminUser(): User
{
    $user = otpMonitorAdminUser();
    $permission = Permission::query()->firstOrCreate([
        'name' => 'otp-movements.test-sms',
        'guard_name' => 'web',
    ], [
        'description' => 'Enviar SMS real de diagnóstico Vonage',
    ]);
    $user->administrator->givePermissionTo($permission);

    return $user->fresh();
}

function enableSmsDiagnostic(string $allowlist = '+525512345678'): void
{
    config([
        'vonage.api_key' => 'test-key',
        'vonage.api_secret' => 'test-secret',
        'vonage.sms_from' => 'FAMEDIC',
        'vonage.sms_diagnostic.enabled' => true,
        'vonage.sms_diagnostic.allowed_destinations' => $allowlist,
        'vonage.sms_diagnostic.production_enabled' => false,
        'vonage.sms_diagnostic.message' => 'Mensaje de prueba FAMEDIC. La conexión SMS con Vonage funciona correctamente.',
    ]);
}

function createOtpMovementEvent(array $overrides = []): OtpMovementEvent
{
    $challengeId = Str::uuid()->toString();
    $correlationId = Str::uuid()->toString();

    return OtpMovementEvent::query()->create(array_merge([
        'occurred_at' => now(),
        'movement_key' => 'corr:'.$correlationId,
        'flow' => OtpMovementFlow::AkubicaLogin->value,
        'operation' => 'request',
        'stage' => OtpMovementStage::ChallengeCreated->value,
        'status' => OtpMovementStatus::InProgress->value,
        'channel' => 'sms',
        'destination_masked' => '***5678',
        'challenge_public_id' => $challengeId,
        'correlation_id' => $correlationId,
        'attempt_number' => 1,
        'endpoint' => 'api/v1/auth/login/request-code',
        'technical_message' => 'Challenge creado; entrega pendiente o en curso.',
        'created_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('usuario sin permiso recibe 403 en listado otp movements', function () {
    $user = User::factory()->create();
    Administrator::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('admin.otp-movements-monitor.index'))
        ->assertForbidden();
});

test('usuario con permiso puede ver listado paginado de movimientos otp', function () {
    $admin = otpMonitorAdminUser();
    createOtpMovementEvent();
    createOtpMovementEvent([
        'flow' => OtpMovementFlow::AkubicaRegister->value,
        'stage' => OtpMovementStage::DecoyIssued->value,
        'status' => OtpMovementStatus::Decoy->value,
        'is_decoy' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.otp-movements-monitor.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/OtpMovements/Index')
            ->has('events.data', 2)
            ->has('summary')
        );
});

test('filtros principales del monitor otp funcionan', function () {
    $admin = otpMonitorAdminUser();
    createOtpMovementEvent([
        'flow' => OtpMovementFlow::AkubicaLogin->value,
        'status' => OtpMovementStatus::Failed->value,
        'stage' => OtpMovementStage::DeliveryFailed->value,
        'provider_alias' => 'fake',
    ]);
    createOtpMovementEvent([
        'flow' => OtpMovementFlow::StepUpResults->value,
        'status' => OtpMovementStatus::Verified->value,
        'stage' => OtpMovementStage::VerifySucceeded->value,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.otp-movements-monitor.index', [
            'flow' => OtpMovementFlow::AkubicaLogin->value,
            'failed_only' => '1',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.flow', OtpMovementFlow::AkubicaLogin->value)
            ->where('filters.failed_only', '1')
            ->has('events.data', 1)
        );
});

test('detalle muestra linea de tiempo ordenada y diagnostico', function () {
    $admin = otpMonitorAdminUser();
    $correlationId = Str::uuid()->toString();
    $movementKey = 'corr:'.$correlationId;

    OtpMovementEvent::query()->create([
        'occurred_at' => now()->subMinute(),
        'movement_key' => $movementKey,
        'flow' => OtpMovementFlow::AkubicaLogin->value,
        'operation' => 'request',
        'stage' => OtpMovementStage::ChallengeCreated->value,
        'status' => OtpMovementStatus::InProgress->value,
        'correlation_id' => $correlationId,
        'channel' => 'sms',
        'destination_masked' => '***1234',
        'created_at' => now()->subMinute(),
    ]);

    OtpMovementEvent::query()->create([
        'occurred_at' => now(),
        'movement_key' => $movementKey,
        'flow' => OtpMovementFlow::AkubicaLogin->value,
        'operation' => 'delivery',
        'stage' => OtpMovementStage::DeliveryAccepted->value,
        'status' => OtpMovementStatus::Sent->value,
        'correlation_id' => $correlationId,
        'provider_alias' => 'fake',
        'provider_result_class' => 'accepted',
        'technical_message' => 'Proveedor aceptó la solicitud de envío; entrega final al dispositivo no confirmada.',
        'created_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.otp-movements-monitor.show', $movementKey))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/OtpMovements/Show')
            ->where('movement_key', $movementKey)
            ->where('diagnosis.summary', 'FAMEDIC envió; Vonage aceptó el SMS')
            ->has('timeline', 2)
        );
});

test('respuesta admin nunca incluye codigo otp en texto plano', function () {
    $admin = otpMonitorAdminUser();
    createOtpMovementEvent([
        'technical_message' => 'Verificación fallida: OTP_INVALID_CODE',
        'meta' => ['reason' => 'invalid_attempt'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.otp-movements-monitor.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.technical_message', 'Verificación fallida: OTP_INVALID_CODE')
        );

    $content = OtpMovementEvent::query()->first()?->technical_message;
    expect($content)->not->toMatch('/\b123456\b/');
    expect($content)->not->toContain('plain_code');
});

test('login otp registra evento decoy en otp_movement_events', function () {
    enableLoginOtpWithFakeDelivery();

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525598765432',
    ], [
        'X-Correlation-Id' => Str::uuid()->toString(),
    ])->assertStatus(202);

    $event = OtpMovementEvent::query()->where('is_decoy', true)->first();

    expect($event)->not->toBeNull()
        ->and($event->meta['decoy_reason'] ?? null)->toBe('user_not_found')
        ->and($event->technical_message)->toContain('user_not_found');
});

test('login otp registra challenge y delivery aceptado', function () {
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ], [
        'X-Correlation-Id' => Str::uuid()->toString(),
    ])->assertStatus(202);

    expect(OtpMovementEvent::query()->where('stage', OtpMovementStage::ChallengeCreated->value)->exists())->toBeTrue();
    expect(OtpMovementEvent::query()->where('stage', OtpMovementStage::DeliveryAccepted->value)->exists())->toBeTrue();
});

test('verify incorrecto registra evento de verificacion fallida', function () {
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ])->assertStatus(202);

    $challengeId = $response->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '000000',
    ])->assertStatus(422);

    expect(OtpMovementEvent::query()->where('stage', OtpMovementStage::VerifyFailed->value)->exists())->toBeTrue();
});

test('replay de idempotencia registra evento replay', function () {
    enableLoginOtpWithFakeDelivery();
    config()->set('api_v1.idempotency.enabled', true);

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $headers = [
        'X-Correlation-Id' => Str::uuid()->toString(),
        'Idempotency-Key' => Str::uuid()->toString(),
    ];

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ], $headers)->assertStatus(202);

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ], array_merge($headers, [
        'X-Correlation-Id' => Str::uuid()->toString(),
    ]))->assertStatus(202);

    expect(OtpMovementEvent::query()->where('is_replay', true)->exists())->toBeTrue();
});

test('historico reconstruye challenge parcial desde otp_challenges', function () {
    $admin = otpMonitorAdminUser();
    $challenge = OtpChallenge::factory()->create([
        'purpose' => OtpMovementFlow::AkubicaLogin->value,
        'destination_masked' => '***9999',
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.otp-movements-monitor.show', 'chal:'.$challenge->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('partial_traceability', true)
            ->has('timeline')
        );
});

test('respuestas api otp mantienen contrato tras instrumentacion', function () {
    enableLoginOtpWithFakeDelivery();

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525598765432',
    ])->assertStatus(202)
        ->assertJsonStructure([
            'success',
            'data' => [
                'requires_otp',
                'challenge_id',
                'purpose',
                'channel',
                'destination_masked',
                'expires_at',
            ],
        ]);
});

test('test sms requiere permiso separado de monitor otp', function () {
    enableSmsDiagnostic();
    $admin = otpMonitorAdminUser();

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525512345678',
            'mode' => 'send_only',
            'confirm' => true,
        ])
        ->assertForbidden();
});

test('test sms queda apagado por defecto aunque el administrador tenga permiso', function () {
    $admin = otpSmsDiagnosticAdminUser();

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525512345678',
            'mode' => 'send_only',
            'confirm' => true,
        ])
        ->assertForbidden();
});

test('test sms solo permite destinos normalizados presentes en allowlist', function () {
    enableSmsDiagnostic('+528119912478');
    $admin = otpSmsDiagnosticAdminUser();

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525512345678',
            'mode' => 'send_only',
            'confirm' => true,
        ])
        ->assertForbidden();
});

test('test sms send only envia mensaje fijo sin callback ni registros otp', function () {
    enableSmsDiagnostic('+525512345678');
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.webhook_token' => str_repeat('a', 32),
        'app.url' => 'http://localhost',
    ]);

    $gateway = new FakeVonageSmsSendGateway;
    $gateway->push(function (\Vonage\SMS\Message\SMS $sms) {
        expect($sms->getDeliveryReceiptCallback())->toBeNull()
            ->and($sms->getMessage())->toBe('Mensaje de prueba FAMEDIC. La conexión SMS con Vonage funciona correctamente.');

        return vonageSmsCollection(0, messageId: 'abcdef123456');
    });
    app()->instance(VonageSmsSendGateway::class, $gateway);
    $admin = otpSmsDiagnosticAdminUser();

    $before = [
        User::count(),
        Customer::count(),
        OtpChallenge::count(),
        OtpCode::count(),
        AkubicaRegistrationIntent::count(),
    ];

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '55 1234 5678',
            'mode' => 'send_only',
            'confirm' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.destination_masked', '***5678')
        ->assertJsonPath('data.callback.applied', false);

    expect($gateway->calls)->toBe(1)
        ->and([
            User::count(),
            Customer::count(),
            OtpChallenge::count(),
            OtpCode::count(),
            AkubicaRegistrationIntent::count(),
        ])->toBe($before);
});

test('test sms con dlr se deshabilita cuando base es localhost', function () {
    enableSmsDiagnostic('+525512345678');
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.webhook_token' => str_repeat('a', 32),
        'app.url' => 'https://localhost',
    ]);

    $admin = otpSmsDiagnosticAdminUser();

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525512345678',
            'mode' => 'send_with_dlr',
            'confirm' => true,
        ])
        ->assertForbidden();
});

test('test sms con dlr adjunta callback solo con base https publica', function () {
    enableSmsDiagnostic('+525512345678');
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.webhook_token' => str_repeat('a', 32),
        'app.url' => 'https://admin.example.com',
    ]);

    $gateway = new FakeVonageSmsSendGateway;
    $gateway->push(function (\Vonage\SMS\Message\SMS $sms) {
        expect($sms->getDeliveryReceiptCallback())
            ->toStartWith('https://admin.example.com/webhooks/vonage/sms/delivery/');

        return vonageSmsCollection(0, messageId: 'abcdef123456');
    });
    app()->instance(VonageSmsSendGateway::class, $gateway);

    $this->actingAs(otpSmsDiagnosticAdminUser())
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525512345678',
            'mode' => 'send_with_dlr',
            'confirm' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.callback.applied', true)
        ->assertJsonPath('data.callback.host', 'admin.example.com');
});

test('test sms aplica limite de tres pruebas por administrador', function () {
    enableSmsDiagnostic('+525512345678,+525598765432,+525500001111,+525500002222');
    $admin = otpSmsDiagnosticAdminUser();

    RateLimiter::clear('otp-movements:test-sms:admin:'.$admin->administrator->id);

    $gateway = new FakeVonageSmsSendGateway;
    for ($i = 0; $i < 3; $i++) {
        $gateway->pushCollection(vonageSmsCollection(0, messageId: 'msg'.$i));
    }
    app()->instance(VonageSmsSendGateway::class, $gateway);

    foreach (['+525512345678', '+525598765432', '+525500001111'] as $destination) {
        $this->actingAs($admin)
            ->postJson(route('admin.otp-movements-monitor.test-sms'), [
                'destination' => $destination,
                'mode' => 'send_only',
                'confirm' => true,
            ])
            ->assertOk();
    }

    $this->actingAs($admin)
        ->postJson(route('admin.otp-movements-monitor.test-sms'), [
            'destination' => '+525500002222',
            'mode' => 'send_only',
            'confirm' => true,
        ])
        ->assertStatus(429);

    expect($gateway->calls)->toBe(3);
});
