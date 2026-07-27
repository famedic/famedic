<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Exceptions\Otp\OtpTemporaryUnavailableException;
use App\Exceptions\Otp\RegistrationCompletedLoginRequiredException;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Models\AkubicaRegistrationIntent;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\RegularAccount;
use App\Models\User;
use App\Services\Otp\MysqlContentionClassifier;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use App\Services\Otp\Registration\PhoneUniquenessAuditor;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function p0a57aEnableSecureRegister(): void
{
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
}

/**
 * @return array{challenge_id: string, email: string, phone: string}
 */
function p0a57aRequestRegister(string $email, string $phone, string $code = '123456'): array
{
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator($code));

    $response = test()->postJson('/api/v1/auth/register', [
        'email' => $email,
        'phone' => $phone,
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    return [
        'challenge_id' => $response->json('data.challenge_id'),
        'email' => $email,
        'phone' => $phone,
    ];
}

beforeEach(function () {
    Notification::fake();
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.flags.infrastructure_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
});

// ── A. Normalization / uniqueness ──────────────────────────────────────

test('p0a57a equivalent phone formats collapse to one canonical identity', function () {
    $user = User::factory()->create([
        'email' => 'canon.owner.p0a57a@ejemplo.test',
        'phone' => '5512347001',
        'phone_country' => 'MX',
    ]);

    expect($user->getRawOriginal('phone') ?? $user->getAttributes()['phone'] ?? null)->toBe('5512347001');

    p0a57aEnableSecureRegister();
    $start = p0a57aRequestRegister('canon.new.p0a57a@ejemplo.test', '+52 55 1234 7001', '111111');

    // Decoy path (phone collision) — no second user.
    expect(app(\App\Services\Otp\Registration\AkubicaRegisterOtpDecoyStore::class)
        ->exists($start['challenge_id']))->toBeTrue()
        ->and(User::query()->where('email', 'canon.new.p0a57a@ejemplo.test')->exists())->toBeFalse();
});

test('p0a57a null phones remain allowed and empty becomes null on create', function () {
    $a = User::factory()->create([
        'email' => 'null.a.p0a57a@ejemplo.test',
        'phone' => null,
        'phone_country' => null,
    ]);
    $b = User::factory()->create([
        'email' => 'null.b.p0a57a@ejemplo.test',
        'phone' => null,
        'phone_country' => null,
    ]);

    expect($a->phone)->toBeNull()
        ->and($b->phone)->toBeNull();

    $created = app(\App\Actions\Users\CreateUserAction::class)(
        email: 'empty.phone.p0a57a@ejemplo.test',
        name: 'Nombre',
        phone: '',
        phoneCountry: 'MX',
        password: 'Secret123!',
    );

    expect($created->phone)->toBeNull()
        ->and($created->phone_country)->toBeNull();
});

test('p0a57a duplicate normalized phone cannot persist when unique index exists', function () {
    if (! collect(Schema::getIndexes('users'))->contains(fn ($i) => ($i['name'] ?? '') === 'users_phone_country_phone_unique')) {
        $this->markTestSkipped('users_phone_country_phone_unique not present');
    }

    User::factory()->create([
        'email' => 'dup.owner.p0a57a@ejemplo.test',
        'phone' => '5512347002',
        'phone_country' => 'MX',
    ]);

    expect(fn () => User::factory()->create([
        'email' => 'dup.other.p0a57a@ejemplo.test',
        'phone' => '5512347002',
        'phone_country' => 'MX',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('p0a57a concurrent-style phone collision via verify returns invalid code without enumeration', function () {
    p0a57aEnableSecureRegister();
    $start = p0a57aRequestRegister('race.phone.p0a57a@ejemplo.test', '+52 55 1234 7003', '222222');

    User::factory()->create([
        'email' => 'race.phone.other.p0a57a@ejemplo.test',
        'phone' => '5512347003',
        'phone_country' => 'MX',
    ]);

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '222222',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    $body = json_encode($response->json());
    expect($body)->not->toContain('PHONE')
        ->and($body)->not->toContain('5512347003')
        ->and($body)->not->toContain('users_phone')
        ->and($body)->not->toContain('23000')
        ->and(User::query()->where('email', 'race.phone.p0a57a@ejemplo.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a57a legacy remains with flags off', function () {
    expect(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(config('sanctum.expiration'))->toBe(1440);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'legacy.p0a57a@ejemplo.test',
        'phone' => '+52 55 1234 7004',
        'full_name' => 'Nombre Apellido',
    ])->assertOk();
});

// ── B. Audit / migration preflight ─────────────────────────────────────

test('p0a57a auditor reports counts without pii and clean db does not block', function () {
    User::factory()->create([
        'email' => 'audit.clean.p0a57a@ejemplo.test',
        'phone' => '5512347005',
        'phone_country' => 'MX',
    ]);

    $report = app(PhoneUniquenessAuditor::class)->audit();

    expect($report['blocks_unique_index'])->toBeFalse()
        ->and($report['literal_duplicate_groups'])->toBe(0)
        ->and(json_encode($report))->not->toContain('5512347005')
        ->and(json_encode($report))->not->toContain('audit.clean');
});

test('p0a57a auditor detects literal duplicate groups', function () {
    Schema::table('users', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->dropUnique('users_phone_country_phone_unique');
    });

    User::factory()->create([
        'email' => 'audit.dup1.p0a57a@ejemplo.test',
        'phone' => '5512347006',
        'phone_country' => 'MX',
    ]);
    User::factory()->create([
        'email' => 'audit.dup2.p0a57a@ejemplo.test',
        'phone' => '5512347006',
        'phone_country' => 'MX',
    ]);

    $report = app(PhoneUniquenessAuditor::class)->audit();

    expect($report['blocks_unique_index'])->toBeTrue()
        ->and($report['literal_duplicate_groups'])->toBeGreaterThanOrEqual(1)
        ->and($report['literal_users_in_dup_groups'])->toBeGreaterThanOrEqual(2)
        ->and(json_encode($report))->not->toContain('@ejemplo');
});

test('p0a57a migration preflight throws when duplicates exist', function () {
    Schema::table('users', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->dropUnique('users_phone_country_phone_unique');
    });

    User::factory()->create([
        'email' => 'mig.dup1.p0a57a@ejemplo.test',
        'phone' => '5512347010',
        'phone_country' => 'MX',
    ]);
    User::factory()->create([
        'email' => 'mig.dup2.p0a57a@ejemplo.test',
        'phone' => '5512347010',
        'phone_country' => 'MX',
    ]);

    $migration = require database_path('migrations/2026_07_27_210000_add_users_phone_country_phone_unique.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Cannot add users_phone_country_phone_unique');
});

test('p0a57a unique index exists after migrations', function () {
    $names = collect(Schema::getIndexes('users'))->pluck('name');
    expect($names)->toContain('users_phone_country_phone_unique');
});

// ── C. MySQL error classification ──────────────────────────────────────

test('p0a57a classifier maps 1213 1205 and 1062 by driver code', function () {
    $classifier = new MysqlContentionClassifier;

    $deadlock = new QueryException(
        'mysql',
        'select 1',
        [],
        new \Exception('Deadlock', 0),
    );
    $deadlock->errorInfo = ['40001', 1213, 'Deadlock found'];

    $lockWait = new QueryException(
        'mysql',
        'select 1',
        [],
        new \Exception('Lock wait', 0),
    );
    $lockWait->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

    $dup = new QueryException(
        'mysql',
        'insert',
        [],
        new \Exception('Duplicate', 0),
    );
    $dup->errorInfo = ['23000', 1062, "Duplicate entry 'MX-5512347007' for key 'users_phone_country_phone_unique'"];

    expect($classifier->classify($deadlock)['kind'])->toBe(MysqlContentionClassifier::KIND_DEADLOCK)
        ->and($classifier->classify($lockWait)['kind'])->toBe(MysqlContentionClassifier::KIND_LOCK_WAIT_TIMEOUT)
        ->and($classifier->classify($dup)['kind'])->toBe(MysqlContentionClassifier::KIND_DUPLICATE_KEY)
        ->and($classifier->classify($dup)['duplicate_users_phone'])->toBeTrue();
});

test('p0a57a mapper hides sqlstate for temporary unavailable and login required', function () {
    $mapper = app(OtpExceptionHttpMapper::class);

    $temp = $mapper->toResponse(new OtpTemporaryUnavailableException);
    $login = $mapper->toResponse(new RegistrationCompletedLoginRequiredException);

    expect($temp->getStatusCode())->toBe(503)
        ->and($temp->getData(true)['error']['code'] ?? null)->toBe('OTP_TEMPORARY_UNAVAILABLE')
        ->and($login->getStatusCode())->toBe(409)
        ->and($login->getData(true)['error']['code'] ?? null)->toBe('LOGIN_REQUIRED');

    $tempBody = json_encode($temp->getData(true));
    $loginBody = json_encode($login->getData(true));

    expect($tempBody)->not->toContain('40001')
        ->and($tempBody)->not->toContain('1213')
        ->and($tempBody)->not->toContain('SQLSTATE')
        ->and($loginBody)->not->toContain('token')
        ->and($loginBody)->not->toContain('Sanctum');
});

// ── D. Token post-commit recovery (D11) ────────────────────────────────

test('p0a57a token issuance failure keeps account consumed and requires login', function () {
    p0a57aEnableSecureRegister();

    $failingToken = \Mockery::mock(\App\Actions\Api\V1\Auth\IssueAkubicaTokenAction::class);
    $failingToken->shouldReceive('__invoke')->andThrow(new \RuntimeException('forced token failure for p0a57a'));
    app()->instance(\App\Actions\Api\V1\Auth\IssueAkubicaTokenAction::class, $failingToken);

    $start = p0a57aRequestRegister('token.fail.p0a57a@ejemplo.test', '+52 55 1234 7008', '333333');

    $beforeUsers = User::query()->count();
    $beforeRa = RegularAccount::query()->count();
    $beforeCustomers = Customer::query()->count();
    $beforeTokens = PersonalAccessToken::query()->count();

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '333333',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'LOGIN_REQUIRED');

    expect(User::query()->count())->toBe($beforeUsers + 1)
        ->and(RegularAccount::query()->count())->toBe($beforeRa + 1)
        ->and(Customer::query()->count())->toBe($beforeCustomers + 1)
        ->and(PersonalAccessToken::query()->count())->toBe($beforeTokens)
        ->and(User::query()->where('email', 'token.fail.p0a57a@ejemplo.test')->count())->toBe(1);

    $challenge = OtpChallenge::query()->where('public_id', $start['challenge_id'])->first();
    $intent = AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->first();

    expect($challenge->consumed_at)->not->toBeNull()
        ->and($intent->status)->toBe(AkubicaRegistrationIntentStatus::Consumed)
        ->and($intent->encrypted_payload)->toBeNull();

    // Repeat verify must not create another account.
    $repeat = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '333333',
    ]);

    $repeat->assertStatus(422);
    expect(User::query()->where('email', 'token.fail.p0a57a@ejemplo.test')->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe($beforeTokens)
        ->and(json_encode($response->json()))->not->toContain('forced token')
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse();

    Notification::assertNothingSent();
});

// ── E. Privacy / side effects ──────────────────────────────────────────

test('p0a57a happy path still issues one token without delivery', function () {
    p0a57aEnableSecureRegister();
    $start = p0a57aRequestRegister('happy.p0a57a@ejemplo.test', '+52 55 1234 7009', '444444');

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '444444',
    ])->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and($response->json('data.token'))->not->toBeEmpty();

    Notification::assertNothingSent();
    expect(config('otp.p0a.flags.akubica_register_enabled'))->toBeTrue()
        ->and(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse();
});
