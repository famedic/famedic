<?php

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use App\Models\ApiConsumerDelegation;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use App\Services\Api\V1\Auth\ConsumerLifecycle;
use App\Services\Api\V1\Auth\ConsumerRegistryWriter;
use App\Services\Api\V1\Auth\RegisteredOtpConsumerEnrollment;
use App\Services\Api\V1\Auth\RegistryOtpEnrollmentOperator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * INT-029: --delegate-v1-user also authorizes the OTP issuer authority of the
 * Leo channel (RegistryOtpEnrollmentOperator) and --reset revokes it.
 * Synthetic data only: fake 555 phone, no SMS/OTP/providers/network.
 */

const INT029_NS = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
const INT029_SCENARIO = 'functional-window-2-continuity';
const INT029_PHONE = '5559990471';

function int029Operator(): User
{
    Storage::fake('local');
    config()->set('akubica_connectors.otp_enrollment', ['enabled' => true, 'certified' => true]);
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'int029-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8330000001 + $index),
            'country' => 'MX',
        ]);
    }
    $code = Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => INT029_NS, '--namespace' => INT029_NS]);
    if ($code !== 0) throw new RuntimeException(Artisan::output());

    $operator = User::factory()->create();
    Administrator::create(['user_id' => $operator->id])
        ->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    return $operator;
}

/** Synthetic stand-in for the akubica-uat-v1 membership_none user. */
function int029V1User(): User
{
    $user = User::factory()->create([
        'phone' => INT029_PHONE,
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email_verified_at' => now(),
    ]);
    Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => AkubicaUatFixtureContract::NAMESPACE,
        'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
        'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
        'metadata' => ['ids' => ['users' => ['primary' => 900101, 'membership_none' => $user->id]]],
    ]);

    return $user->fresh();
}

function int029Run(array $options): array
{
    $code = Artisan::call('akubica:uat-access', $options + ['--namespace' => INT029_NS]);
    $out = trim(Artisan::output());

    return ['code' => $code, 'out' => $out, 'json' => json_decode($out, true)];
}

function int029State(): array
{
    return json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-state.json', INT029_NS)), true, 512, JSON_THROW_ON_ERROR);
}

function int029Inventory(): array
{
    return json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-inventory.json', INT029_NS)), true, 512, JSON_THROW_ON_ERROR);
}

/** The same path verify-code takes: machine principal → issuer authority → delegation. */
function int029Grant(User $v1, int $consumerId, int $channelId): mixed
{
    $client = new AuthenticatedBackendClient((string) $consumerId, (string) $channelId, now()->addMinutes(10)->timestamp);
    $authenticator = new class($client) implements BackendClientAuthenticator {
        public function __construct(private AuthenticatedBackendClient $client) {}

        public function authenticate(Request $request): ?AuthenticatedBackendClient { return $this->client; }
    };

    return (new RegisteredOtpConsumerEnrollment($authenticator, new RegistryOtpEnrollmentOperator))->grant($v1->fresh(), Request::create('/api/v1/auth/login/verify-code', 'POST'));
}

function int029Apply(User $v1): array
{
    return int029Run(['--apply' => true, '--scenario' => INT029_SCENARIO, '--confirm' => INT029_NS, '--delegate-v1-user' => (string) $v1->id]);
}

test('INT-029 delegate-v1-user authorizes exactly one OTP issuer on the Leo channel bounded by the Leo credential and verify-code can enroll', function () {
    $operator = int029Operator();
    $v1 = int029V1User();

    $before = DB::table('api_consumer_otp_issuers')->count();
    $dry = int029Run(['--dry-run' => true, '--scenario' => INT029_SCENARIO, '--delegate-v1-user' => (string) $v1->id]);
    expect($dry['code'])->toBe(0, $dry['out'])
        ->and($dry['json']['effects']['v1_otp_issuers'])->toBe(1)
        ->and(DB::table('api_consumer_otp_issuers')->count())->toBe($before);

    $apply = int029Apply($v1);
    expect($apply['code'])->toBe(0, $apply['out'])
        ->and($apply['json']['created'])->toBe(['consumers' => 2, 'channels' => 2, 'delegations' => 6, 'credentials' => 2, 'bindings' => 5, 'tokens' => 7, 'otp_issuers' => 1])
        ->and($apply['out'])->not->toContain(INT029_PHONE)->not->toContain('0471');

    $state = int029State();
    $leoConsumer = ApiConsumer::query()->findOrFail($state['consumer_ids']['leo']);
    $leoChannel = ApiConsumerChannel::query()->findOrFail($state['channel_ids']['leo']);
    $leoCredential = ApiConsumerCredential::query()->findOrFail($state['credential_ids']['leo']);
    expect($state['v1_otp_issuer_ids'])->toHaveKey('leo_v1_otp_delegate');

    $issuer = DB::table('api_consumer_otp_issuers')->where('id', $state['v1_otp_issuer_ids']['leo_v1_otp_delegate'])->first();
    expect($issuer)->not->toBeNull()
        ->and((int) $issuer->consumer_id)->toBe($leoConsumer->id)
        ->and((int) $issuer->channel_id)->toBe($leoChannel->id)
        ->and((int) $issuer->operator_id)->toBe($operator->id)
        ->and((bool) $issuer->enabled)->toBeTrue()
        ->and($issuer->approval_ref)->toBe('RC2_UAT_ACCESS')
        ->and(Carbon::parse($issuer->expires_at)->timestamp)->toBe($leoCredential->expires_at->timestamp)
        ->and(Carbon::parse($issuer->expires_at)->lte($leoChannel->expires_at))->toBeTrue()
        // Only the Leo channel of this run; never the native channel.
        ->and(DB::table('api_consumer_otp_issuers')->count())->toBe($before + 1)
        ->and(DB::table('api_consumer_otp_issuers')->where('channel_id', $state['channel_ids']['native'])->exists())->toBeFalse()
        ->and(int029Inventory()['state']['v1_otp_issuer_ids'])->toBe($state['v1_otp_issuer_ids']);

    $client = new AuthenticatedBackendClient((string) $leoConsumer->id, (string) $leoChannel->id, now()->addMinutes(10)->timestamp);
    expect((new RegistryOtpEnrollmentOperator)->operatorFor($client, $v1)?->id)->toBe($operator->id);

    $grant = int029Grant($v1, $leoConsumer->id, $leoChannel->id);
    expect($grant->delegation->id)->toBe($state['v1_delegation_ids']['leo_v1_otp_delegate'])
        ->and($grant->capabilities)->toBe(['memberships:read'])
        ->and($grant->operator->id)->toBe($operator->id);

    // Ownership is untouched: another user's verify-code is still refused (no delegation).
    $stranger = User::factory()->create(['phone_verified_at' => now()]);
    Customer::factory()->withRegularAccount()->create(['user_id' => $stranger->id]);
    expect(fn () => int029Grant($stranger, $leoConsumer->id, $leoChannel->id))->toThrow(AuthorizationException::class);
    // The native channel has no issuer authority, so verify-code stays fail closed there.
    expect(fn () => int029Grant($v1, (int) $state['consumer_ids']['native'], (int) $state['channel_ids']['native']))->toThrow(AuthorizationException::class);

    $again = int029Apply($v1);
    expect($again['json']['status'])->toBe('idempotent')->and(DB::table('api_consumer_otp_issuers')->count())->toBe($before + 1);
});

test('INT-029 continuity reset revokes the OTP issuer it authorized and verify-code is refused again', function () {
    int029Operator();
    $v1 = int029V1User();
    expect(int029Apply($v1)['code'])->toBe(0);
    $state = int029State();
    $issuerId = $state['v1_otp_issuer_ids']['leo_v1_otp_delegate'];

    $reset = int029Run(['--reset' => true, '--confirm' => INT029_NS, '--scenario' => INT029_SCENARIO]);
    expect($reset['code'])->toBe(0, $reset['out'])
        ->and($reset['json']['status'])->toBe('ok')
        ->and($reset['json']['deleted'])->toBe(25);

    $issuer = DB::table('api_consumer_otp_issuers')->where('id', $issuerId)->first();
    expect((bool) $issuer->enabled)->toBeFalse()
        ->and(DB::table('api_consumer_otp_issuers')->where('enabled', true)->count())->toBe(0)
        ->and(DB::table('api_consumer_lifecycle_events')->where('record_type', 'otp_issuer')->where('record_id', $issuerId)->where('action', 'revoke')->count())->toBe(1)
        ->and(int029Inventory()['status'])->toBe('completed')
        ->and(int029Inventory()['state']['v1_otp_issuer_ids'])->toBe(['leo_v1_otp_delegate' => $issuerId])
        ->and(ApiConsumerDelegation::query()->where('user_id', $v1->id)->whereNull('revoked_at')->count())->toBe(0);

    $client = new AuthenticatedBackendClient((string) $state['consumer_ids']['leo'], (string) $state['channel_ids']['leo'], now()->addMinutes(10)->timestamp);
    expect((new RegistryOtpEnrollmentOperator)->operatorFor($client, $v1))->toBeNull()
        ->and(fn () => int029Grant($v1, (int) $state['consumer_ids']['leo'], (int) $state['channel_ids']['leo']))->toThrow(AuthorizationException::class);

    // A fresh window works again after the reset (new channel, new issuer).
    $next = int029Apply($v1);
    expect($next['code'])->toBe(0, $next['out'])
        ->and(int029State()['v1_otp_issuer_ids']['leo_v1_otp_delegate'])->not->toBe($issuerId)
        ->and(DB::table('api_consumer_otp_issuers')->where('enabled', true)->count())->toBe(1);
});

test('INT-029 reset refuses an OTP issuer id outside the state graph and leaves it untouched', function () {
    $operator = int029Operator();
    $v1 = int029V1User();
    expect(int029Apply($v1)['code'])->toBe(0);

    // Foreign issuer authority (another consumer/channel, not owned by this run).
    $registry = app(ConsumerRegistryWriter::class);
    $foreignConsumer = $registry->register($operator, 'leo', ['memberships:read'], now()->addMinutes(20));
    $foreignExpiry = Carbon::parse($foreignConsumer->fresh()->expires_at);
    $foreignChannel = $registry->channel($operator, $foreignConsumer, $foreignExpiry);
    app(ConsumerLifecycle::class)->authorizeOtp($operator, $foreignChannel, $operator, 'FOREIGN_OTP_ISSUER', $foreignExpiry->copy()->subMinute());
    $foreignId = (int) DB::table('api_consumer_otp_issuers')->where('channel_id', $foreignChannel->id)->value('id');

    $state = int029State();
    $state['v1_otp_issuer_ids'] = ['leo_v1_otp_delegate' => $foreignId];
    Storage::disk('local')->put(AkubicaUatFixtureContract::storagePath('access-state.json', INT029_NS), json_encode($state));

    $reset = int029Run(['--reset' => true, '--confirm' => INT029_NS, '--scenario' => INT029_SCENARIO]);
    expect($reset['code'])->toBe(1)
        ->and($reset['json']['error_code'])->toBe('UAT_ACCESS_INVENTORY_OTP_ISSUER_SCOPE_INVALID')
        ->and((bool) DB::table('api_consumer_otp_issuers')->where('id', $foreignId)->value('enabled'))->toBeTrue();
});

test('INT-029 without the option no OTP issuer is authorized and output, state and reset are unchanged', function () {
    int029Operator();
    $v1 = int029V1User();
    $before = DB::table('api_consumer_otp_issuers')->count();

    $dry = int029Run(['--dry-run' => true, '--scenario' => INT029_SCENARIO]);
    expect($dry['code'])->toBe(0)->and($dry['json']['effects'])->not->toHaveKey('v1_otp_issuers');

    $apply = int029Run(['--apply' => true, '--scenario' => INT029_SCENARIO, '--confirm' => INT029_NS]);
    expect($apply['code'])->toBe(0)
        ->and($apply['json']['created'])->toBe(['consumers' => 2, 'channels' => 2, 'delegations' => 5, 'credentials' => 2, 'bindings' => 5, 'tokens' => 7])
        ->and(int029State())->not->toHaveKey('v1_otp_issuer_ids')
        ->and(DB::table('api_consumer_otp_issuers')->count())->toBe($before);

    $state = int029State();
    expect(fn () => int029Grant($v1, (int) $state['consumer_ids']['leo'], (int) $state['channel_ids']['leo']))->toThrow(AuthorizationException::class);

    $reset = int029Run(['--reset' => true, '--confirm' => INT029_NS, '--scenario' => INT029_SCENARIO]);
    expect($reset['code'])->toBe(0)
        ->and($reset['json']['deleted'])->toBe(23)
        ->and(DB::table('api_consumer_otp_issuers')->count())->toBe($before)
        ->and(DB::table('api_consumer_lifecycle_events')->where('record_type', 'otp_issuer')->count())->toBe(0);
});