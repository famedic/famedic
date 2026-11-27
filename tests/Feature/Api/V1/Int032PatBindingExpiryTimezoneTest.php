<?php

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Models\Administrator;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Permission;
use App\Models\User;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use App\Services\Api\V1\Auth\ConsumerRegistryState;
use App\Services\Api\V1\Auth\NativeConsumerMigration;
use App\Services\Api\V1\Auth\OtpConsumerEnrollment;
use App\Services\Api\V1\Auth\OtpEnrollmentGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * INT-032 · OBS-SEC-PAT-EXPIRY-TZ / OBS-SEC-A10-TOKEN-EXPIRY-TZ: the Akubica PAT and its consumer binding must expire
 * at the announced instant (legacy 1440 min, P0-C1 TTL or the capped registry/delegation lifetime) regardless of the
 * application timezone, with the database session in UTC as in QA. Same defect class as INT-030 (PR #96):
 * Carbon::createFromTimestampUTC() produced a UTC-zoned instance whose wall-clock time was persisted and later read
 * back in the app timezone (+6 h with America/Mexico_City). Synthetic users and registry rows only; no SMS, OTP,
 * providers or network. Nothing is relaxed: the same caps, TTLs and binding checks run unchanged.
 */

const INT032_CAPS = ['memberships:read'];

dataset('int032_timezones', ['America/Mexico_City', 'UTC']);

beforeEach(function () {
    $this->int032Tz = [config('app.timezone'), date_default_timezone_get()];
    disableAllAkubicaOtpFeatures();
    config()->set('sanctum.expiration', 1440);
    if (DB::getDriverName() === 'mysql') {
        DB::statement("SET time_zone = '+00:00'");
    }
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
    config()->set('app.timezone', $this->int032Tz[0]);
    date_default_timezone_set($this->int032Tz[1]);
});

function int032UseTimezone(string $tz): void
{
    config()->set('app.timezone', $tz);
    date_default_timezone_set($tz);
    // Same wall-clock moment as the QA run that exposed the defect (PAT 458, 2026-11-27 08:29:09 CST).
    // The frozen clock is zoned in the app timezone, as now() is in a real request.
    Carbon::setTestNow(Carbon::parse('2026-11-27 06:29:09', 'America/Mexico_City')->setTimezone($tz));
}

/** Synthetic registry: administrator operator, verified user with customer, active consumer/channel/delegation. */
function int032Registry(string $kind, int $registryMinutes): array
{
    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    $user = User::factory()->withRegularCustomer()->create();
    $until = now()->addMinutes($registryMinutes);
    $consumer = ApiConsumer::forceCreate(['kind' => $kind, 'capabilities' => INT032_CAPS, 'enabled' => true, 'expires_at' => $until]);
    $channel = ApiConsumerChannel::forceCreate(['consumer_id' => $consumer->id, 'enabled' => true, 'expires_at' => $until]);
    $delegation = ApiConsumerDelegation::forceCreate(['consumer_id' => $consumer->id, 'channel_id' => $channel->id, 'user_id' => $user->id,
        'customer_id' => $user->customer->id, 'capabilities' => INT032_CAPS, 'enabled' => true, 'expires_at' => $until]);

    return compact('operator', 'user', 'consumer', 'channel', 'delegation');
}

/** The OTP enrollment grant as RegisteredOtpConsumerEnrollment returns it: an epoch timestamp (min of the registry lifetimes). */
function int032Grant(array $r): void
{
    $expiresAt = min($r['consumer']->expires_at->timestamp, $r['channel']->expires_at->timestamp, $r['delegation']->expires_at->timestamp);
    app()->instance(OtpConsumerEnrollment::class, new class($r['operator'], $r['delegation'], $expiresAt) implements OtpConsumerEnrollment
    {
        public function __construct(private User $operator, private ApiConsumerDelegation $delegation, private int $expiresAt) {}

        public function grant(User $verifiedUser, Request $request): ?OtpEnrollmentGrant
        {
            return new OtpEnrollmentGrant($this->operator, $this->delegation, INT032_CAPS, $this->expiresAt);
        }
    });
}

/** PAT and binding read back from the database carry exactly $expected, stored as wall-clock time in the app timezone. */
function int032AssertPersisted(int $patId, Carbon $expected): ApiConsumerTokenBinding
{
    $wall = $expected->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    $pat = PersonalAccessToken::query()->findOrFail($patId);
    $binding = ApiConsumerTokenBinding::query()->where('personal_access_token_id', $patId)->sole();
    expect($pat->expires_at->getTimestamp())->toBe($expected->getTimestamp())
        ->and($binding->expires_at->getTimestamp())->toBe($expected->getTimestamp())
        ->and(DB::table('personal_access_tokens')->where('id', $patId)->value('expires_at'))->toBe($wall)
        ->and(DB::table('api_consumer_token_bindings')->where('id', $binding->id)->value('expires_at'))->toBe($wall);

    return $binding;
}

function int032Issue(User $user): array
{
    $payload = app(IssueAkubicaTokenAction::class)($user);

    return [$payload, PersonalAccessToken::findToken($payload['token'])->id];
}

test('legacy TTL with an OTP enrollment grant: expires_in exactly 86400 and PAT/binding persist that instant', function (string $tz) {
    int032UseTimezone($tz);
    $r = int032Registry('leo', 2 * 24 * 60);
    int032Grant($r);

    [$payload, $patId] = int032Issue($r['user']);
    $expected = now()->addMinutes(1440);

    expect($payload['expires_in'])->toBe(86400)
        ->and($payload['expires_at'])->toBe($expected->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
    int032AssertPersisted($patId, $expected);
})->with('int032_timezones');

test('P0-C1 flag on with an OTP enrollment grant: expires_in exactly the configured 180 minutes', function (string $tz) {
    int032UseTimezone($tz);
    enableSanctum3hTokenExpiration(180);
    $r = int032Registry('leo', 2 * 24 * 60);
    int032Grant($r);

    [$payload, $patId] = int032Issue($r['user']);

    expect($payload['expires_in'])->toBe(180 * 60);
    int032AssertPersisted($patId, now()->addMinutes(180));
})->with('int032_timezones');

test('grant capped by the delegation lifetime: expires_in is the remaining delegation time, never more', function (string $tz) {
    int032UseTimezone($tz);
    $r = int032Registry('leo', 45);
    int032Grant($r);

    [$payload, $patId] = int032Issue($r['user']);

    expect($payload['expires_in'])->toBe(45 * 60);
    int032AssertPersisted($patId, now()->addMinutes(45));
})->with('int032_timezones');

test('the issued token is rejected right after its expiry and accepted right before', function (string $tz) {
    int032UseTimezone($tz);
    Route::middleware('auth:sanctum')->get('/__int032/whoami', fn () => response()->json(['ok' => true]));
    $r = int032Registry('leo', 45);
    int032Grant($r);
    [$payload, $patId] = int032Issue($r['user']);
    $binding = ApiConsumerTokenBinding::query()->where('personal_access_token_id', $patId)->sole();
    $expiry = now()->addMinutes(45);

    Carbon::setTestNow($expiry->copy()->subSecond());
    $this->app['auth']->forgetGuards();
    $this->getJson('/__int032/whoami', ['Authorization' => 'Bearer '.$payload['token']])->assertOk();
    expect(ConsumerRegistryState::active($binding->fresh()))->toBeTrue();

    Carbon::setTestNow($expiry->copy()->addSecond());
    $this->app['auth']->forgetGuards();
    $this->getJson('/__int032/whoami', ['Authorization' => 'Bearer '.$payload['token']])->assertUnauthorized();
    expect(ConsumerRegistryState::active($binding->fresh()))->toBeFalse();
})->with('int032_timezones');

test('native renewal: renewed PAT and binding expire at the requested instant and not later', function (string $tz) {
    int032UseTimezone($tz);
    $r = int032Registry('native', 2 * 24 * 60);
    $backend = \Mockery::mock(BackendClientAuthenticator::class);
    $backend->shouldReceive('authenticate')->andReturn(new AuthenticatedBackendClient((string) $r['consumer']->id, (string) $r['channel']->id, now()->addMinutes(60)->timestamp));
    app()->instance(BackendClientAuthenticator::class, $backend);
    $requested = now()->addMinutes(30);

    $renewed = app(NativeConsumerMigration::class)->renew($r['operator'], $r['delegation'], Request::create('/'), $requested->copy());

    $binding = int032AssertPersisted((int) $renewed['token']->accessToken->id, $requested);
    Carbon::setTestNow($requested->copy()->addSecond());
    expect(ConsumerRegistryState::active($binding->fresh()))->toBeFalse()
        ->and(PersonalAccessToken::query()->findOrFail($renewed['token']->accessToken->id)->expires_at->isPast())->toBeTrue();
})->with('int032_timezones');