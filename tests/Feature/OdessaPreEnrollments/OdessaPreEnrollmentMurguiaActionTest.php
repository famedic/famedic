<?php

use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Actions\Odessa\RetryPreEnrollmentMurguiaAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'famedic.odessa_pre_enrollments.enabled' => true,
        'famedic.odessa_pre_enrollments.generate_credit_enabled' => true,
        'famedic.odessa_pre_enrollments.murguia_enabled' => true,
        'famedic.odessa_pre_enrollments.murguia_retry_enabled' => true,
        'famedic.odessa_pre_enrollments.murguia_product' => 'SYNTHETIC_PRODUCT',
        'famedic.odessa_pre_enrollments.murguia_subproduct' => 'SYNTHETIC_SUBPRODUCT',
        'famedic.odessa_pre_enrollments.membership_starts_at' => now()->addMonth()->toDateString(),
        'famedic.odessa_pre_enrollments.membership_ends_at' => now()->addMonths(13)->toDateString(),
        'famedic.odessa_pre_enrollments.murguia_not_found_codes' => [],
        'services.murguia.url' => 'https://murguia.test/',
    ]);
    Queue::fake();
});

function odessaMurguiaAdmin(array $permissions = [
    'odessa-pre-enrollments.view',
    'odessa-pre-enrollments.manage',
    'odessa-pre-enrollments.actions.generate-credit',
    'odessa-pre-enrollments.actions.murguia-register',
    'odessa-pre-enrollments.actions.murguia-verify',
    'odessa-pre-enrollments.actions.murguia-retry',
]): User {
    $role = Role::firstOrCreate(['name' => 'PreEnrollment Murguia Admin '.md5(implode('|', $permissions)), 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->assignRole($role);

    return $user->fresh('administrator.roles.permissions');
}

function odessaMurguiaPreEnrollment(array $attributes = []): OdessaPreEnrollment
{
    return OdessaPreEnrollment::factory()->create([
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'data_quality_flags' => null,
        'membership_type' => 'institutional',
        'medical_attention_identifier' => '7345678901',
        ...$attributes,
    ]);
}

function odessaMurguiaHttpSequence(array $register, array $readback): void
{
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200),
        '*/asegurados/registro' => Http::sequence()
            ->push($register['body'], $register['status']),
        '*/asegurados/consultar-estatus' => Http::sequence()
            ->push($readback['body'], $readback['status']),
    ]);
}

function odessaMurguiaAuditPayload(): string
{
    return json_encode(OdessaPreEnrollmentAudit::query()->get(['action_type', 'before_json', 'after_json', 'reason'])->toArray());
}

it('registers a ready pre enrollment and read-back marks it active without creating FAMEDIC entities', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    $before = [
        'users' => User::count(),
        'customers' => Customer::count(),
        'odessa' => OdessaAfiliateAccount::count(),
        'subscriptions' => MedicalAttentionSubscription::count(),
    ];
    odessaMurguiaHttpSequence(
        ['status' => 200, 'body' => ['success' => true]],
        ['status' => 200, 'body' => ['success' => true, 'estatus' => 'activo']],
    );

    $response = $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), [
            'confirmation' => 'REGISTRAR',
            'medical_attention_identifier' => '0000000000',
            'murguia_status' => OdessaPreEnrollment::MURGUIA_ACTIVE,
        ]);

    $preEnrollment = $preEnrollment->fresh();
    $response->assertRedirect(route('admin.odessa.pre-enrollments.show', $preEnrollment));
    expect($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_ACTIVE)
        ->and($preEnrollment->murguia_attempts)->toBe(1)
        ->and($preEnrollment->medical_attention_identifier)->toBe('7345678901')
        ->and(session('success'))->not->toContain('7345678901')
        ->and(User::count())->toBe($before['users'])
        ->and(Customer::count())->toBe($before['customers'])
        ->and(OdessaAfiliateAccount::count())->toBe($before['odessa'])
        ->and(MedicalAttentionSubscription::count())->toBe($before['subscriptions']);

    Queue::assertNothingPushed();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://murguia.test/asegurados/registro'
        && $request['noCredito'] === '7345678901'
        && filled($request['nombre'])
        && $request['campaña'] === 'Famedic'
        && $request['producto'] === 'SYNTHETIC_PRODUCT'
        && $request['subProducto'] === 'SYNTHETIC_SUBPRODUCT'
        && preg_match('/^\d{2}-\d{2}-\d{4}$/', $request['inicioVigencia'])
        && preg_match('/^\d{2}-\d{2}-\d{4}$/', $request['finVigencia']));
    expect(odessaMurguiaAuditPayload())->not->toContain('7345678901', '@', 'request', 'response');
});

it('treats duplicated register responses as read-back and can mark inactive', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    odessaMurguiaHttpSequence(
        ['status' => 409, 'body' => ['message' => 'duplicado']],
        ['status' => 200, 'body' => ['success' => true, 'estatus' => 'inactivo']],
    );

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertRedirect();

    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_INACTIVE)
        ->and(OdessaPreEnrollmentAudit::where('action_type', 'MURGUIA_REGISTER_ACCEPTED')->exists())->toBeTrue()
        ->and(OdessaPreEnrollmentAudit::where('action_type', 'MURGUIA_READBACK_INACTIVE')->exists())->toBeTrue();
});

it('stores definitive rejections as failed with controlled event codes only', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/registro' => Http::sequence()->push(['message' => 'sensitive@example.test 7345678901'], 422),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_FAILED)
        ->and($preEnrollment->fresh()->murguia_last_event_code)->toBe('MURGUIA_REGISTER_REJECTED')
        ->and(odessaMurguiaAuditPayload())->not->toContain('7345678901', 'sensitive@example.test');
});

it('keeps unknown network outcomes pending and does not duplicate while lease is active', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('network synthetic failure'));

    $first = $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR']);
    $second = $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR']);

    $first->assertRedirect();
    $second->assertSessionHasErrors('murguia_register');
    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->fresh()->murguia_attempts)->toBe(1)
        ->and($preEnrollment->fresh()->murguia_last_event_code)->toBe('MURGUIA_REGISTER_OUTCOME_UNKNOWN');
});

it('requires reserved identifier flags and permissions for Murguia endpoints', function () {
    $preEnrollment = odessaMurguiaPreEnrollment(['medical_attention_identifier' => null]);
    $admin = odessaMurguiaAdmin();

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    config(['famedic.odessa_pre_enrollments.murguia_enabled' => false]);
    $preEnrollment->update(['medical_attention_identifier' => '7345678901']);
    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.verify', $preEnrollment))
        ->assertSessionHasErrors('murguia_verify');

    config(['famedic.odessa_pre_enrollments.murguia_enabled' => true]);
    $this->actingAs(odessaMurguiaAdmin(['odessa-pre-enrollments.view']))
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertForbidden();
});

it('requires manage plus the specific Murguia permission', function () {
    $preEnrollment = odessaMurguiaPreEnrollment(['medical_attention_identifier' => null]);

    $this->actingAs(odessaMurguiaAdmin([
        'odessa-pre-enrollments.view',
        'odessa-pre-enrollments.actions.murguia-register',
    ]))->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertForbidden();

    $this->actingAs(odessaMurguiaAdmin([
        'odessa-pre-enrollments.view',
        'odessa-pre-enrollments.manage',
    ]))->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertForbidden();

    $this->actingAs(odessaMurguiaAdmin([
        'odessa-pre-enrollments.view',
        'odessa-pre-enrollments.manage',
        'odessa-pre-enrollments.actions.murguia-register',
    ]))->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');
});

it('retry verifies first preserves the same identifier and only registers after explicit not found', function () {
    config(['famedic.odessa_pre_enrollments.murguia_not_found_codes' => ['NO_ENCONTRADO']]);
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
        'murguia_attempts' => 1,
    ]);
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200),
        '*/asegurados/consultar-estatus' => Http::sequence()
            ->push(['success' => false, 'error_code' => 'NO_ENCONTRADO'], 404)
            ->push(['success' => true, 'estatus' => 'activo'], 200),
        '*/asegurados/registro' => Http::sequence()
            ->push(['success' => true], 200)
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertRedirect();

    $preEnrollment = $preEnrollment->fresh();
    expect($preEnrollment->medical_attention_identifier)->toBe('7345678901')
        ->and($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_ACTIVE)
        ->and($preEnrollment->murguia_attempts)->toBe(2);

    $urls = [];
    Http::assertSent(function (Request $request) use (&$urls) {
        $urls[] = $request->url();

        return true;
    });
    expect($urls)->toContain('https://murguia.test/asegurados/consultar-estatus')
        ->and(array_search('https://murguia.test/asegurados/consultar-estatus', $urls, true))
        ->toBeLessThan(array_search('https://murguia.test/asegurados/registro', $urls, true));
});

it('active pre enrollments do not register again', function () {
    Http::fake();
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_ACTIVE,
        'murguia_attempts' => 1,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertRedirect();

    Http::assertNothingSent();
    expect($preEnrollment->fresh()->murguia_attempts)->toBe(1);
});

it('exposes the reserved identifier to manage users without exposing operation tokens', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    odessaMurguiaHttpSequence(
        ['status' => 200, 'body' => ['success' => true]],
        ['status' => 200, 'body' => ['success' => true, 'estatus' => 'activo']],
    );

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR']);

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.show', $preEnrollment->fresh()))
        ->assertOk()
        ->assertDontSee('murguia_operation_token')
        ->assertInertia(fn (Assert $page) => $page
            ->where('preEnrollment.medical_attention_identifier', '7345678901')
            ->missing('preEnrollment.murguia_operation_token')
            ->missing('preEnrollment.murguia_correlation_id'));
});

it('sends complete sanitized Murguia props to show', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.show', $preEnrollment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Odessa/PreEnrollments/Show')
            ->where('canGenerateCredit', true)
            ->where('canRegisterMurguia', true)
            ->where('canVerifyMurguia', true)
            ->where('canRetryMurguia', true)
            ->where('creditGenerationEnabled', true)
            ->where('murguiaEnabled', true)
            ->where('murguiaRetryEnabled', true)
            ->where('murguiaContractConfigured', true)
            ->where('preEnrollment.medical_attention_identifier', '7345678901')
            ->missing('preEnrollment.murguia_operation_token')
            ->missing('preEnrollment.murguia_correlation_id'));
});

it('blocks register before HTTP when Murguia contract config is incomplete', function () {
    config(['famedic.odessa_pre_enrollments.murguia_product' => null]);
    Http::fake();

    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    Http::assertNothingSent();
    expect($preEnrollment->fresh()->murguia_last_event_code)->toBeNull()
        ->and(odessaMurguiaAuditPayload())->toContain('MURGUIA_CONTRACT_NOT_CONFIGURED')
        ->not->toContain('SYNTHETIC_PRODUCT', 'SYNTHETIC_SUBPRODUCT', '7345678901');
});

it('blocks register before HTTP when configured dates are invalid or expired', function (array $dates) {
    config($dates);
    Http::fake();

    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    Http::assertNothingSent();
    expect(odessaMurguiaAuditPayload())->toContain('MURGUIA_CONTRACT_NOT_CONFIGURED');
})->with([
    'missing start' => [[
        'famedic.odessa_pre_enrollments.membership_starts_at' => null,
    ]],
    'same dates' => [[
        'famedic.odessa_pre_enrollments.membership_starts_at' => now()->addMonth()->toDateString(),
        'famedic.odessa_pre_enrollments.membership_ends_at' => now()->addMonth()->toDateString(),
    ]],
    'expired end' => [[
        'famedic.odessa_pre_enrollments.membership_starts_at' => now()->subMonths(2)->toDateString(),
        'famedic.odessa_pre_enrollments.membership_ends_at' => now()->subMonth()->toDateString(),
    ]],
]);

it('blocks register when existing membership dates differ from configured contract', function () {
    Http::fake();

    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'membership_start_date' => now()->addDays(10)->toDateString(),
        'membership_end_date' => now()->addYear()->toDateString(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    Http::assertNothingSent();
    expect(odessaMurguiaAuditPayload())->toContain('MURGUIA_MEMBERSHIP_DATES_MISMATCH')
        ->not->toContain('7345678901');
});

it('classifies ambiguous read-back responses as unknown and retry does not register', function (int $status, array $body) {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
        'murguia_attempts' => 1,
    ]);
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/consultar-estatus' => Http::sequence()->push($body, $status),
        '*/asegurados/registro' => Http::sequence()->push(['success' => true], 200),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertSessionHasErrors('murguia_retry');

    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->fresh()->murguia_last_event_code)->toBe('MURGUIA_READBACK_UNKNOWN');

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://murguia.test/asegurados/registro');
})->with([
    'success false 2xx' => [200, ['success' => false]],
    'empty body 2xx' => [200, []],
    'unknown status 2xx' => [200, ['success' => true, 'estatus' => 'pendiente']],
    '404 empty body' => [404, []],
    '410 empty body' => [410, []],
    '404 success false without code' => [404, ['success' => false]],
    '404 non allowlisted code' => [404, ['error_code' => 'UNLISTED_NOT_FOUND']],
    'message text ignored' => [404, ['message' => 'NO_ENCONTRADO']],
]);

it('ignores stale successful register responses without overwriting the current operation', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    $newToken = '11111111-1111-4111-8111-111111111111';
    $newCorrelation = '22222222-2222-4222-8222-222222222222';

    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/registro' => function () use ($preEnrollment, $newToken, $newCorrelation) {
            $preEnrollment->fresh()->forceFill([
                'murguia_operation_token' => $newToken,
                'murguia_correlation_id' => $newCorrelation,
                'murguia_attempts' => 2,
                'murguia_last_event_code' => 'MURGUIA_REGISTER_STARTED',
            ])->save();

            return Http::response(['success' => true], 200);
        },
        '*/asegurados/consultar-estatus' => Http::response(['success' => true, 'estatus' => 'activo'], 200),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    $preEnrollment = $preEnrollment->fresh();
    expect($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->murguia_attempts)->toBe(2)
        ->and($preEnrollment->murguia_last_http_status)->toBeNull()
        ->and($preEnrollment->murguia_last_event_code)->toBe('MURGUIA_REGISTER_STARTED')
        ->and($preEnrollment->murguia_operation_token)->toBe($newToken)
        ->and($preEnrollment->murguia_correlation_id)->toBe($newCorrelation)
        ->and(odessaMurguiaAuditPayload())->toContain('STALE_OPERATION_RESULT_IGNORED')
        ->not->toContain('7345678901');
});

it('ignores stale register errors without overwriting the current operation', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment();
    $newToken = '33333333-3333-4333-8333-333333333333';
    $newCorrelation = '44444444-4444-4444-8444-444444444444';

    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/registro' => function () use ($preEnrollment, $newToken, $newCorrelation) {
            $preEnrollment->fresh()->forceFill([
                'murguia_operation_token' => $newToken,
                'murguia_correlation_id' => $newCorrelation,
                'murguia_attempts' => 2,
                'murguia_last_event_code' => 'MURGUIA_REGISTER_STARTED',
            ])->save();

            throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
        },
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertSessionHasErrors('murguia_register');

    $preEnrollment = $preEnrollment->fresh();
    expect($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->murguia_attempts)->toBe(2)
        ->and($preEnrollment->murguia_last_http_status)->toBeNull()
        ->and($preEnrollment->murguia_last_event_code)->toBe('MURGUIA_REGISTER_STARTED')
        ->and($preEnrollment->murguia_operation_token)->toBe($newToken)
        ->and($preEnrollment->murguia_correlation_id)->toBe($newCorrelation)
        ->and(odessaMurguiaAuditPayload())->toContain('STALE_OPERATION_RESULT_IGNORED')
        ->not->toContain('synthetic timeout', '7345678901');
});

it('uses only explicit not found read-back to allow retry registration', function () {
    config(['famedic.odessa_pre_enrollments.murguia_not_found_codes' => ['NO_ENCONTRADO']]);
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
        'murguia_attempts' => 1,
    ]);
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200)
            ->push(['token' => 'fake-token'], 200),
        '*/asegurados/consultar-estatus' => Http::sequence()
            ->push(['success' => false, 'result_code' => 'NO_ENCONTRADO'], 200)
            ->push(['success' => true, 'estatus' => 'activo'], 200),
        '*/asegurados/registro' => Http::sequence()->push(['success' => true], 200),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertRedirect();

    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_ACTIVE)
        ->and($preEnrollment->fresh()->murguia_attempts)->toBe(2);
});

it('never treats explicit not found codes as not found when the allowlist is empty', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
        'murguia_attempts' => 1,
    ]);
    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/consultar-estatus' => Http::sequence()->push(['success' => false, 'error_code' => 'NO_ENCONTRADO'], 404),
        '*/asegurados/registro' => Http::sequence()->push(['success' => true], 200),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertSessionHasErrors('murguia_retry');

    expect($preEnrollment->fresh()->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->fresh()->murguia_last_event_code)->toBe('MURGUIA_READBACK_UNKNOWN');

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://murguia.test/asegurados/registro');
});

it('does not mutate audit or call HTTP when retry flags are disabled', function (array $flags, string $expectedCode) {
    Http::fake();
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
        'murguia_attempts' => 1,
        'murguia_last_event_code' => 'UNCHANGED_EVENT',
    ]);
    config($flags);

    $directResult = app(RetryPreEnrollmentMurguiaAction::class)->execute($preEnrollment->fresh(), $admin);
    expect($directResult['code'])->toBe($expectedCode);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertSessionHasErrors('murguia_retry');

    $preEnrollment = $preEnrollment->fresh();
    expect($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_FAILED)
        ->and($preEnrollment->murguia_attempts)->toBe(1)
        ->and($preEnrollment->murguia_last_event_code)->toBe('UNCHANGED_EVENT')
        ->and(OdessaPreEnrollmentAudit::count())->toBe(0)
        ->and(session('errors')->getBag('default')->first('murguia_retry'))->not->toContain('7345678901');

    Http::assertNothingSent();
})->with([
    'general off retry on' => [[
        'famedic.odessa_pre_enrollments.murguia_enabled' => false,
        'famedic.odessa_pre_enrollments.murguia_retry_enabled' => true,
    ], 'flag_off'],
    'general on retry off' => [[
        'famedic.odessa_pre_enrollments.murguia_enabled' => true,
        'famedic.odessa_pre_enrollments.murguia_retry_enabled' => false,
    ], 'retry_flag_off'],
]);

it('ignores stale read-back responses without overwriting the current operation', function () {
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment([
        'murguia_status' => OdessaPreEnrollment::MURGUIA_PENDING,
        'murguia_pending_since' => now()->subMinutes(10),
        'murguia_attempts' => 2,
    ]);
    $newToken = '55555555-5555-4555-8555-555555555555';
    $newCorrelation = '66666666-6666-4666-8666-666666666666';

    Http::fake([
        '*/api/Security/Auth' => Http::sequence()->push(['token' => 'fake-token'], 200),
        '*/asegurados/consultar-estatus' => function () use ($preEnrollment, $newToken, $newCorrelation) {
            $preEnrollment->fresh()->forceFill([
                'murguia_operation_token' => $newToken,
                'murguia_correlation_id' => $newCorrelation,
                'murguia_last_event_code' => 'MURGUIA_REGISTER_STARTED',
            ])->save();

            return Http::response(['success' => true, 'estatus' => 'activo'], 200);
        },
    ]);

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.verify', $preEnrollment))
        ->assertSessionHasErrors('murguia_verify');

    $preEnrollment = $preEnrollment->fresh();
    expect($preEnrollment->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_PENDING)
        ->and($preEnrollment->murguia_synced_at)->toBeNull()
        ->and($preEnrollment->murguia_last_http_status)->toBeNull()
        ->and($preEnrollment->murguia_last_event_code)->toBe('MURGUIA_REGISTER_STARTED')
        ->and($preEnrollment->murguia_operation_token)->toBe($newToken)
        ->and($preEnrollment->murguia_correlation_id)->toBe($newCorrelation)
        ->and(odessaMurguiaAuditPayload())->toContain('STALE_OPERATION_RESULT_IGNORED')
        ->not->toContain('7345678901');
});

it('rate limits individual Murguia endpoints by user bucket', function () {
    Http::fake();
    RateLimiter::clear('1');
    $admin = odessaMurguiaAdmin();
    $preEnrollment = odessaMurguiaPreEnrollment(['medical_attention_identifier' => null]);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($admin)
            ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
            ->assertRedirect();
    }
    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.register', $preEnrollment), ['confirmation' => 'REGISTRAR'])
        ->assertTooManyRequests();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($admin)
            ->post(route('admin.odessa.pre-enrollments.murguia.verify', $preEnrollment))
            ->assertRedirect();
    }
    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.verify', $preEnrollment))
        ->assertTooManyRequests();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($admin)
            ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
            ->assertRedirect();
    }
    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.murguia.retry', $preEnrollment), ['confirmation' => 'REINTENTAR'])
        ->assertTooManyRequests();
});
