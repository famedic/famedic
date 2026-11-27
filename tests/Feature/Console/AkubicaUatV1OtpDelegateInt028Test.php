<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Services\Api\V1\Auth\ConsumerRegistryState;
use App\Services\Api\V1\Auth\ConsumerTokenBindingWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * INT-028: opt-in --delegate-v1-user for akubica:uat-access.
 * Synthetic data only: phones are fake 555 numbers, no SMS/OTP/providers.
 */

const INT028_NS = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
const INT028_SCENARIO = 'functional-window-2-continuity';
const INT028_PHONE = '5559990470';

function int028Operator(): User
{
    Storage::fake('local');
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'int028-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8320000001 + $index),
            'country' => 'MX',
        ]);
    }
    $code = Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => INT028_NS, '--namespace' => INT028_NS]);
    if ($code !== 0) throw new RuntimeException(Artisan::output());

    $operator = User::factory()->create();
    Administrator::create(['user_id' => $operator->id])
        ->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    return $operator;
}

/** Synthetic stand-in for the akubica-uat-v1 membership_none user (QA 12979). */
function int028V1User(bool $verified = true, string $manifestStatus = AkubicaUatFixtureContract::STATUS_ACTIVE, bool $inManifest = true): User
{
    $user = User::factory()->create([
        'phone' => INT028_PHONE,
        'phone_country' => 'MX',
        'phone_verified_at' => $verified ? now() : null,
        'email_verified_at' => now(),
    ]);
    Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => AkubicaUatFixtureContract::NAMESPACE,
        'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
        'status' => $manifestStatus,
        'metadata' => ['ids' => ['users' => ['primary' => 900001, 'membership_none' => $inManifest ? $user->id : 900002]]],
    ]);

    return $user->fresh();
}

function int028Run(array $options): array
{
    $code = Artisan::call('akubica:uat-access', $options + ['--namespace' => INT028_NS]);

    $out = trim(Artisan::output());
    return ['code' => $code, 'out' => $out, 'json' => json_decode($out, true)];
}

function int028Snapshot(): array
{
    return [
        'consumers' => ApiConsumer::query()->count(),
        'channels' => ApiConsumerChannel::query()->count(),
        'delegations' => ApiConsumerDelegation::query()->count(),
        'bindings' => ApiConsumerTokenBinding::query()->count(),
        'tokens' => PersonalAccessToken::query()->count(),
        'lifecycle' => DB::table('api_consumer_lifecycle_events')->count(),
        'users' => DB::table('users')->orderBy('id')->get()->map(fn ($u) => (array) $u)->all(),
        'customers' => DB::table('customers')->orderBy('id')->get()->map(fn ($c) => (array) $c)->all(),
        'manifests' => DB::table('akubica_uat_fixture_manifests')->orderBy('id')->get()->map(fn ($m) => (array) $m)->all(),
        'files' => Storage::disk('local')->allFiles(INT028_NS),
    ];
}

function int028State(): array
{
    return json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-state.json', INT028_NS)), true, 512, JSON_THROW_ON_ERROR);
}

test('INT-028 delegates exactly one active Leo delegation to a verified akubica-uat-v1 user without touching the user', function () {
    int028Operator();
    $v1 = int028V1User();
    $userBefore = (array) DB::table('users')->where('id', $v1->id)->first();
    $customerBefore = (array) DB::table('customers')->where('user_id', $v1->id)->first();
    $manifestBefore = (array) DB::table('akubica_uat_fixture_manifests')->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->first();

    $apply = int028Run(['--apply' => true, '--scenario' => INT028_SCENARIO, '--confirm' => INT028_NS, '--delegate-v1-user' => (string) $v1->id]);
    expect($apply['code'])->toBe(0, $apply['out'])
        ->and($apply['json']['status'])->toBe('ok')
        ->and($apply['json']['created']['delegations'])->toBe(6)
        ->and($apply['out'])->not->toContain(INT028_PHONE)->not->toContain('0470');

    $state = int028State();
    expect($state['v1_delegate_user_id'])->toBe($v1->id)
        ->and($state['user_ids']['v1_otp_delegate'])->toBe($v1->id)
        ->and($state['v1_delegation_ids'])->toHaveKey('leo_v1_otp_delegate');

    $leoConsumer = ApiConsumer::query()->findOrFail($state['consumer_ids']['leo']);
    $leoChannel = ApiConsumerChannel::query()->findOrFail($state['channel_ids']['leo']);
    expect($leoConsumer->kind)->toBe('leo');
    // Same lookup RegisteredOtpConsumerEnrollment::grant() performs.
    $active = ApiConsumerDelegation::query()->where('consumer_id', $leoConsumer->id)->where('channel_id', $leoChannel->id)
        ->where('user_id', $v1->id)->where('customer_id', $v1->customer->id)->get()
        ->filter(fn ($d) => ConsumerRegistryState::active($d));
    expect($active)->toHaveCount(1)
        ->and($active->sole()->id)->toBe($state['v1_delegation_ids']['leo_v1_otp_delegate'])
        ->and(array_values(array_intersect(ConsumerRegistryState::capabilities($leoConsumer->capabilities), ConsumerRegistryState::capabilities($active->sole()->capabilities))))->toBe(['memberships:read'])
        ->and(ApiConsumerDelegation::query()->where('user_id', $v1->id)->count())->toBe(1)
        ->and(PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $v1->id)->count())->toBe(0)
        ->and((array) DB::table('users')->where('id', $v1->id)->first())->toBe($userBefore)
        ->and((array) DB::table('customers')->where('user_id', $v1->id)->first())->toBe($customerBefore)
        ->and((array) DB::table('akubica_uat_fixture_manifests')->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->first())->toBe($manifestBefore);

    $again = int028Run(['--apply' => true, '--scenario' => INT028_SCENARIO, '--confirm' => INT028_NS, '--delegate-v1-user' => (string) $v1->id]);
    expect($again['json']['status'])->toBe('idempotent')
        ->and(ApiConsumerDelegation::query()->where('user_id', $v1->id)->count())->toBe(1);
    $mismatch = int028Run(['--apply' => true, '--scenario' => INT028_SCENARIO, '--confirm' => INT028_NS]);
    expect($mismatch['code'])->toBe(1)->and($mismatch['json']['error_code'])->toBe('UAT_ACCESS_V1_DELEGATE_MISMATCH');
});

test('INT-028 rejects non-v1, inactive, unverified, unknown or malformed delegates in dry-run and apply without writes', function () {
    $operator = int028Operator();
    $batch2Primary = (int) data_get(AkubicaUatFixtureManifest::query()->where('namespace', INT028_NS)->sole()->metadata, 'ids.users.primary');
    $notInManifest = User::factory()->create(['phone_verified_at' => now()]);
    Customer::factory()->withRegularAccount()->create(['user_id' => $notInManifest->id]);

    $run = function (string $expected, string $id, array $extra = []) {
        $before = int028Snapshot();
        foreach ([['--dry-run' => true], ['--apply' => true, '--confirm' => INT028_NS]] as $mode) {
            $result = int028Run($extra + $mode + ['--scenario' => INT028_SCENARIO, '--delegate-v1-user' => $id]);
            expect($result['code'])->toBe(1, $result['out'])
                ->and($result['json']['error_code'])->toBe($expected)
                ->and($result['out'])->not->toContain(INT028_PHONE);
        }
        expect(int028Snapshot())->toBe($before);
    };

    // Manifest akubica-uat-v1 missing entirely.
    $run('UAT_ACCESS_V1_FIXTURES_NOT_ACTIVE', (string) $notInManifest->id);

    $v1 = int028V1User();
    $run('UAT_ACCESS_V1_USER_NOT_IN_MANIFEST', (string) $batch2Primary);
    $run('UAT_ACCESS_V1_USER_NOT_IN_MANIFEST', (string) $notInManifest->id);
    $run('UAT_ACCESS_V1_USER_NOT_IN_MANIFEST', '987654321');
    $run('UAT_ACCESS_V1_DELEGATE_INVALID', 'abc');
    $run('UAT_ACCESS_V1_DELEGATE_INVALID', '0');
    $run('UAT_ACCESS_V1_DELEGATE_SCENARIO_NOT_ALLOWED', (string) $v1->id, ['--scenario' => 'functional-window-2']);

    // Listed in the manifest but the user row does not exist.
    DB::table('akubica_uat_fixture_manifests')->where('namespace', AkubicaUatFixtureContract::NAMESPACE)
        ->update(['metadata' => json_encode(['ids' => ['users' => ['membership_none' => $v1->id, 'ghost' => 987654322]]])]);
    $run('UAT_ACCESS_V1_USER_NOT_FOUND', '987654322');

    // Unverified phone.
    DB::table('users')->where('id', $v1->id)->update(['phone_verified_at' => null]);
    $run('UAT_ACCESS_V1_USER_NOT_VERIFIED', (string) $v1->id);
    DB::table('users')->where('id', $v1->id)->update(['phone_verified_at' => now()]);

    // Inactive v1 fixtures (manifest not active).
    DB::table('akubica_uat_fixture_manifests')->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->update(['status' => AkubicaUatFixtureContract::STATUS_RESETTING]);
    $run('UAT_ACCESS_V1_FIXTURES_NOT_ACTIVE', (string) $v1->id);

    // --reset never accepts the option.
    $before = int028Snapshot();
    $reset = int028Run(['--reset' => true, '--confirm' => INT028_NS, '--scenario' => INT028_SCENARIO, '--delegate-v1-user' => (string) $v1->id]);
    expect($reset['json']['error_code'])->toBe('UAT_ACCESS_V1_DELEGATE_RESET_NOT_ALLOWED')->and(int028Snapshot())->toBe($before)
        ->and(ApiConsumerDelegation::query()->count())->toBe(0);
});

test('INT-028 continuity reset revokes the v1 delegation and its verify-code binding and leaves the v1 user intact', function () {
    $operator = int028Operator();
    $v1 = int028V1User();
    $userBefore = (array) DB::table('users')->where('id', $v1->id)->first();
    expect(int028Run(['--apply' => true, '--scenario' => INT028_SCENARIO, '--confirm' => INT028_NS, '--delegate-v1-user' => (string) $v1->id])['code'])->toBe(0);
    $state = int028State();
    $delegation = ApiConsumerDelegation::query()->findOrFail($state['v1_delegation_ids']['leo_v1_otp_delegate']);

    // Emulates what IssueAkubicaTokenAction does after a valid verify-code (A10).
    $expiry = \Illuminate\Support\Carbon::parse($delegation->expires_at);
    $pat = $v1->createToken('akubica-api', ['memberships:read'], $expiry);
    $loginBinding = app(ConsumerTokenBindingWriter::class)->bind($operator, $pat->accessToken, $delegation, ['memberships:read'], $expiry);

    $reset = int028Run(['--reset' => true, '--confirm' => INT028_NS, '--scenario' => INT028_SCENARIO]);
    expect($reset['code'])->toBe(0, $reset['out'])->and($reset['json']['status'])->toBe('ok');

    $delegation->refresh();
    $loginBinding->refresh();
    expect($delegation->enabled)->toBeFalsy()->and($delegation->revoked_at)->not->toBeNull()
        ->and($loginBinding->enabled)->toBeFalsy()->and($loginBinding->revoked_at)->not->toBeNull()
        ->and(ApiConsumerDelegation::query()->where('user_id', $v1->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(ApiConsumer::query()->whereIn('id', array_values($state['consumer_ids']))->whereNull('revoked_at')->count())->toBe(0)
        ->and((array) DB::table('users')->where('id', $v1->id)->first())->toBe($userBefore)
        ->and(Customer::query()->where('user_id', $v1->id)->count())->toBe(1)
        ->and(AkubicaUatFixtureManifest::query()->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->value('status'))->toBe('active')
        ->and(Storage::disk('local')->exists(AkubicaUatFixtureContract::storagePath('access-state.json', INT028_NS)))->toBeFalse();
    $inventory = json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-inventory.json', INT028_NS)), true);
    expect($inventory['status'])->toBe('completed')
        ->and($inventory['state']['v1_delegation_ids']['leo_v1_otp_delegate'])->toBe($delegation->id)
        ->and($inventory['state']['v1_delegate_user_id'])->toBe($v1->id);
});

test('INT-028 dry-run with the v1 delegate validates and plans without any write', function () {
    int028Operator();
    $v1 = int028V1User();
    $before = int028Snapshot();
    $dry = int028Run(['--dry-run' => true, '--scenario' => INT028_SCENARIO, '--delegate-v1-user' => (string) $v1->id]);
    expect($dry['code'])->toBe(0, $dry['out'])
        ->and($dry['json']['effects']['v1_delegations'])->toBe(1)
        ->and($dry['json']['effects']['v1_tokens'])->toBe(0)
        ->and($dry['json']['v1_delegation']['identity'])->toBe('UAT-akubica-uat-v1-membership_none-U'.$v1->id)
        ->and($dry['json']['v1_delegation']['capabilities'])->toBe(['memberships:read'])
        ->and($dry['out'])->not->toContain(INT028_PHONE)->not->toContain('0470')
        ->and(int028Snapshot())->toBe($before);
});

test('INT-028 without the option the continuity scenario output, state and delegations are unchanged', function () {
    int028Operator();
    $v1 = int028V1User();
    $dry = int028Run(['--dry-run' => true, '--scenario' => INT028_SCENARIO]);
    expect($dry['code'])->toBe(0)
        ->and($dry['json'])->not->toHaveKey('v1_delegation')
        ->and($dry['json']['effects'])->toBe(['creates' => ['2 consumers', '2 channels', '4 delegations', '2 machine credentials', '4 delegated bindings'], 'external_calls' => 0, 'sms' => 0, 'email' => 0, 'payments' => 0, 'scenario_delegations' => 1, 'scenario_bindings' => 1, 'scenario_tokens' => 1]);
    $apply = int028Run(['--apply' => true, '--scenario' => INT028_SCENARIO, '--confirm' => INT028_NS]);
    expect($apply['code'])->toBe(0)
        ->and($apply['json']['created'])->toBe(['consumers' => 2, 'channels' => 2, 'delegations' => 5, 'credentials' => 2, 'bindings' => 5, 'tokens' => 7]);
    $state = int028State();
    expect($state)->not->toHaveKey('v1_delegation_ids')->not->toHaveKey('v1_delegate_user_id')
        ->and(array_keys($state['user_ids']))->toBe(['primary', 'foreign', 'membership_regular', 'membership_none'])
        ->and(ApiConsumerDelegation::query()->where('user_id', $v1->id)->count())->toBe(0);
    $reset = int028Run(['--reset' => true, '--confirm' => INT028_NS, '--scenario' => INT028_SCENARIO]);
    expect($reset['code'])->toBe(0)->and($reset['json']['deleted'])->toBe(23);
});
