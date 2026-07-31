<?php

use App\Enums\AkubicaRegistrationIntentStatus;
use App\Enums\P0aOtpPurpose;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpRateLimit;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\AkubicaOtpPruningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-31 12:00:00', config('app.timezone')));
    config()->set('otp.p0a.cleanup.enabled', false);
    config()->set('otp.p0a.cleanup.challenges_retention_days', 30);
    config()->set('otp.p0a.cleanup.deliveries_retention_days', 30);
    config()->set('otp.p0a.cleanup.rate_limits_retention_days', 7);
    config()->set('otp.p0a.cleanup.grants_retention_days', 30);
    config()->set('otp.p0a.cleanup.secure_links_retention_days', 30);
    config()->set('otp.p0a.cleanup.default_batch', 1000);
    config()->set('otp.p0a.anti_abuse.rate_limit_window_minutes', 30);
});

afterEach(function () {
    Carbon::setTestNow();
});

function p0c2User(): User
{
    return User::factory()->create([
        'phone' => '5511112222',
        'phone_country' => 'MX',
    ]);
}

function p0c2Challenge(array $overrides = []): OtpChallenge
{
    return OtpChallenge::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'user_id' => null,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5511112222',
        'purpose' => P0aOtpPurpose::AkubicaLogin->value,
        'channel' => 'sms',
        'destination_normalized' => '+525511112222',
        'destination_masked' => '***2222',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->subDays(40),
        'consumed_at' => null,
        'invalidated_at' => null,
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now()->subDays(40),
    ], $overrides));
}

function p0c2Grant(User $user, OtpChallenge $challenge, array $overrides = []): OtpStepUpGrant
{
    return OtpStepUpGrant::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => null,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'resource_id' => 1,
        'granted_at' => now()->subDays(40),
        'expires_at' => now()->subDays(40),
        'revoked_at' => null,
    ], $overrides));
}

function p0c2Link(User $user, OtpStepUpGrant $grant, array $overrides = []): OtpSecureDownloadLink
{
    return OtpSecureDownloadLink::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'token_hash' => hash('sha256', 'p0c2-opaque-'.Str::uuid()),
        'user_id' => $user->id,
        'personal_access_token_id' => null,
        'otp_step_up_grant_id' => $grant->id,
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'resource_id' => 1,
        'expires_at' => now()->subDays(40),
        'max_opens' => 1,
        'open_count' => 0,
        'consumed_at' => null,
        'revoked_at' => null,
        'last_opened_at' => null,
    ], $overrides));
}

function p0c2Delivery(OtpChallenge $challenge, array $overrides = []): OtpDeliveryOperation
{
    $attrs = array_merge([
        'operation_key' => hash('sha256', 'delivery|'.Str::uuid()),
        'otp_challenge_id' => $challenge->id,
        'purpose' => $challenge->purpose,
        'status' => 'sms_accepted',
        'primary_channel' => 'sms',
        'fallback_used' => false,
        'provider_alias' => 'fake',
        'result_class' => 'accepted',
        'attempt_count' => 1,
        'correlation_id' => (string) Str::uuid(),
    ], $overrides);

    $createdAt = $attrs['created_at'] ?? now()->subDays(40);
    $updatedAt = $attrs['updated_at'] ?? now()->subDays(40);
    unset($attrs['created_at'], $attrs['updated_at']);

    $operation = OtpDeliveryOperation::query()->create($attrs);
    $operation->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ])->saveQuietly();

    return $operation->refresh();
}

function p0c2RateLimit(array $overrides = []): OtpRateLimit
{
    $attrs = array_merge([
        'bucket_type' => OtpRateLimit::BUCKET_IDENTITY,
        'bucket_key_hash' => hash('sha256', 'rl|'.Str::uuid()),
        'purpose' => P0aOtpPurpose::AkubicaLogin->value,
        'window_started_at' => now()->subDays(10),
        'request_count' => 1,
        'last_allowed_at' => now()->subDays(10),
        'blocked_until' => null,
        'last_challenge_id' => null,
    ], $overrides);

    $createdAt = $attrs['created_at'] ?? now()->subDays(10);
    $updatedAt = $attrs['updated_at'] ?? now()->subDays(10);
    unset($attrs['created_at'], $attrs['updated_at']);

    $row = OtpRateLimit::query()->create($attrs);
    $row->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ])->saveQuietly();

    return $row->refresh();
}

test('p0c2 dry-run counts but does not delete', function () {
    $challenge = p0c2Challenge(['expires_at' => now()->subDays(40)]);
    p0c2Delivery($challenge, ['status' => 'sms_accepted', 'updated_at' => now()->subDays(40)]);
    p0c2RateLimit(['updated_at' => now()->subDays(10), 'window_started_at' => now()->subDays(10)]);

    $user = p0c2User();
    $c2 = p0c2Challenge(['user_id' => $user->id, 'expires_at' => now()->subDays(40), 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c2, ['expires_at' => now()->subDays(40)]);
    p0c2Link($user, $grant, ['expires_at' => now()->subDays(40), 'consumed_at' => now()->subDays(40)]);

    $beforeChallenges = OtpChallenge::query()->count();
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: true, batch: 1000, type: 'all');

    expect($result->dryRun)->toBeTrue()
        ->and($result->secureDownloadLinks)->toBeGreaterThan(0)
        ->and($result->stepUpGrants)->toBeGreaterThan(0)
        ->and($result->deliveryOperations)->toBeGreaterThan(0)
        ->and($result->challenges)->toBeGreaterThan(0)
        ->and($result->rateLimits)->toBeGreaterThan(0)
        ->and(OtpChallenge::query()->count())->toBe($beforeChallenges)
        ->and(OtpSecureDownloadLink::query()->count())->toBe(1)
        ->and(OtpStepUpGrant::query()->count())->toBe(1);
});

test('p0c2 active records are never deleted', function () {
    $user = p0c2User();
    $activeChallenge = p0c2Challenge([
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => null,
        'invalidated_at' => null,
    ]);
    $activeDelivery = p0c2Delivery($activeChallenge, [
        'status' => 'pending',
        'updated_at' => now()->subDays(40),
    ]);
    $activeRl = p0c2RateLimit([
        'window_started_at' => now()->subMinutes(5),
        'updated_at' => now(),
        'blocked_until' => now()->addMinutes(10),
    ]);
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()]);
    $activeGrant = p0c2Grant($user, $c, ['expires_at' => now()->addMinutes(10), 'granted_at' => now()]);
    $activeLink = p0c2Link($user, $activeGrant, [
        'expires_at' => now()->addHour(),
        'consumed_at' => null,
        'revoked_at' => null,
    ]);

    app(AkubicaOtpPruningService::class)->prune(dryRun: false, batch: 100, type: 'all');

    expect(OtpChallenge::query()->find($activeChallenge->id))->not->toBeNull()
        ->and(OtpDeliveryOperation::query()->find($activeDelivery->id))->not->toBeNull()
        ->and(OtpRateLimit::query()->find($activeRl->id))->not->toBeNull()
        ->and(OtpStepUpGrant::query()->find($activeGrant->id))->not->toBeNull()
        ->and(OtpSecureDownloadLink::query()->find($activeLink->id))->not->toBeNull();
});

test('p0c2 recently expired challenge is kept until retention', function () {
    $recent = p0c2Challenge(['expires_at' => now()->subDays(5)]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'challenges');
    expect(OtpChallenge::query()->find($recent->id))->not->toBeNull();
});

test('p0c2 old expired challenge is deleted', function () {
    $old = p0c2Challenge(['expires_at' => now()->subDays(40)]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'challenges');
    expect($result->challenges)->toBe(1)
        ->and(OtpChallenge::query()->find($old->id))->toBeNull();
});

test('p0c2 old consumed challenge is deleted', function () {
    $old = p0c2Challenge([
        'expires_at' => now()->subDays(35),
        'consumed_at' => now()->subDays(35),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'challenges');
    expect(OtpChallenge::query()->find($old->id))->toBeNull();
});

test('p0c2 challenge with remaining grant dependency is not deleted', function () {
    $user = p0c2User();
    $challenge = p0c2Challenge([
        'user_id' => $user->id,
        'expires_at' => now()->subDays(40),
        'consumed_at' => now()->subDays(40),
    ]);
    // Grant still within retention → blocks challenge delete
    p0c2Grant($user, $challenge, [
        'expires_at' => now()->subDays(5),
        'granted_at' => now()->subDays(5),
    ]);

    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'challenges');

    expect(OtpChallenge::query()->find($challenge->id))->not->toBeNull()
        ->and($result->skipped['challenges_remaining_grants'])->toBeGreaterThan(0);
});

test('p0c2 old challenge with registration intent is skipped', function () {
    $challenge = p0c2Challenge([
        'purpose' => 'akubica_register',
        'expires_at' => now()->subDays(40),
        'consumed_at' => now()->subDays(40),
    ]);
    AkubicaRegistrationIntent::query()->create([
        'otp_challenge_id' => $challenge->id,
        'status' => AkubicaRegistrationIntentStatus::Expired,
        'encrypted_payload' => null,
        'payload_version' => 1,
        'email_fingerprint' => hash('sha256', 'p0c2-intent'),
        'expires_at' => now()->subDays(40),
        'consumed_at' => null,
        'invalidated_at' => null,
        'invalidation_reason' => null,
        'superseded_by_id' => null,
    ]);

    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'challenges');

    expect(OtpChallenge::query()->find($challenge->id))->not->toBeNull()
        ->and($result->skipped['challenges_registration_intent'])->toBe(1)
        ->and($result->challenges)->toBe(0);
});

test('p0c2 pending delivery is never deleted', function () {
    $challenge = p0c2Challenge();
    $pending = p0c2Delivery($challenge, [
        'status' => 'pending',
        'updated_at' => now()->subDays(40),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'deliveries');
    expect(OtpDeliveryOperation::query()->find($pending->id))->not->toBeNull();
});

test('p0c2 old terminal delivery is deleted', function () {
    $challenge = p0c2Challenge();
    $done = p0c2Delivery($challenge, [
        'status' => 'sms_accepted',
        'updated_at' => now()->subDays(40),
    ]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'deliveries');
    expect($result->deliveryOperations)->toBe(1)
        ->and(OtpDeliveryOperation::query()->find($done->id))->toBeNull();
});

test('p0c2 active rate limit window is kept', function () {
    $rl = p0c2RateLimit([
        'window_started_at' => now()->subMinutes(5),
        'updated_at' => now()->subDays(10),
        'blocked_until' => null,
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'rate-limits');
    expect(OtpRateLimit::query()->find($rl->id))->not->toBeNull();
});

test('p0c2 old closed rate limit is deleted', function () {
    $rl = p0c2RateLimit([
        'window_started_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
        'blocked_until' => now()->subDays(9),
    ]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'rate-limits');
    expect($result->rateLimits)->toBe(1)
        ->and(OtpRateLimit::query()->find($rl->id))->toBeNull();
});

test('p0c2 active grant is never deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()]);
    $grant = p0c2Grant($user, $c, [
        'expires_at' => now()->addMinutes(10),
        'granted_at' => now(),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect(OtpStepUpGrant::query()->find($grant->id))->not->toBeNull();
});

test('p0c2 old expired grant is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c, ['expires_at' => now()->subDays(40)]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect($result->stepUpGrants)->toBe(1)
        ->and(OtpStepUpGrant::query()->find($grant->id))->toBeNull();
});

test('p0c2 old revoked grant is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c, [
        'expires_at' => now()->addDays(1),
        'revoked_at' => now()->subDays(40),
        'granted_at' => now()->subDays(41),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect(OtpStepUpGrant::query()->find($grant->id))->toBeNull();
});

test('p0c2 grant with active secure link is not deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c, ['expires_at' => now()->subDays(40)]);
    p0c2Link($user, $grant, [
        'expires_at' => now()->addHour(),
        'consumed_at' => null,
        'revoked_at' => null,
    ]);

    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');

    expect(OtpStepUpGrant::query()->find($grant->id))->not->toBeNull()
        ->and($result->skipped['grants_active_secure_links'])->toBe(1);
});

test('p0c2 orphan grant still active is not deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()]);
    $grant = p0c2Grant($user, $c, [
        'personal_access_token_id' => 9_999_999,
        'expires_at' => now()->addMinutes(10),
        'granted_at' => now(),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect(OtpStepUpGrant::query()->find($grant->id))->not->toBeNull();
});

test('p0c2 orphan terminal grant past retention is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c, [
        'personal_access_token_id' => 9_999_998,
        'expires_at' => now()->subDays(40),
        'granted_at' => now()->subDays(40),
    ]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect($result->stepUpGrants)->toBe(1)
        ->and(OtpStepUpGrant::query()->find($grant->id))->toBeNull();
});

test('p0c2 grant with null pat and bind off is not treated as orphan-only delete', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()]);
    $grant = p0c2Grant($user, $c, [
        'personal_access_token_id' => null,
        'expires_at' => now()->addMinutes(10),
        'granted_at' => now(),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'grants');
    expect(OtpStepUpGrant::query()->find($grant->id))->not->toBeNull();
});

test('p0c2 active secure link is never deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()]);
    $grant = p0c2Grant($user, $c, ['expires_at' => now()->addMinutes(10), 'granted_at' => now()]);
    $link = p0c2Link($user, $grant, ['expires_at' => now()->addHour()]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'secure-links');
    expect(OtpSecureDownloadLink::query()->find($link->id))->not->toBeNull();
});

test('p0c2 old consumed secure link is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c);
    $link = p0c2Link($user, $grant, [
        'expires_at' => now()->subDays(39),
        'consumed_at' => now()->subDays(40),
    ]);
    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'secure-links');
    expect($result->secureDownloadLinks)->toBe(1)
        ->and(OtpSecureDownloadLink::query()->find($link->id))->toBeNull();
});

test('p0c2 old expired secure link is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c);
    $link = p0c2Link($user, $grant, ['expires_at' => now()->subDays(40)]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'secure-links');
    expect(OtpSecureDownloadLink::query()->find($link->id))->toBeNull();
});

test('p0c2 old revoked secure link is deleted', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c);
    $link = p0c2Link($user, $grant, [
        'expires_at' => now()->addDay(),
        'revoked_at' => now()->subDays(40),
    ]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'secure-links');
    expect(OtpSecureDownloadLink::query()->find($link->id))->toBeNull();
});

test('p0c2 dry-run and force share the same candidate set', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'expires_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c, ['expires_at' => now()->subDays(40)]);
    p0c2Link($user, $grant, ['expires_at' => now()->subDays(40), 'consumed_at' => now()->subDays(40)]);
    p0c2Delivery($c, ['status' => 'email_failed', 'updated_at' => now()->subDays(40)]);
    p0c2RateLimit(['updated_at' => now()->subDays(10), 'window_started_at' => now()->subDays(10)]);

    $service = app(AkubicaOtpPruningService::class);
    $linkIds = $service->candidateIds('secure-links');
    $grantIds = $service->candidateIds('grants');
    $deliveryIds = $service->candidateIds('deliveries');
    $challengeIds = $service->challengesQuery(ignoreGrantIds: $grantIds)->orderBy('id')->pluck('id')->all();
    $rateLimitIds = $service->candidateIds('rate-limits');

    $dry = $service->prune(dryRun: true, type: 'all');
    expect($dry->secureDownloadLinks)->toBe(count($linkIds))
        ->and($dry->stepUpGrants)->toBe(count($grantIds))
        ->and($dry->deliveryOperations)->toBe(count($deliveryIds))
        ->and($dry->challenges)->toBe(count($challengeIds))
        ->and($dry->rateLimits)->toBe(count($rateLimitIds));

    $force = $service->prune(dryRun: false, type: 'all');
    expect($force->secureDownloadLinks)->toBe($dry->secureDownloadLinks)
        ->and($force->stepUpGrants)->toBe($dry->stepUpGrants)
        ->and($force->deliveryOperations)->toBe($dry->deliveryOperations)
        ->and($force->challenges)->toBe($dry->challenges)
        ->and($force->rateLimits)->toBe($dry->rateLimits)
        ->and($service->candidateIds('secure-links'))->toBe([])
        ->and($service->candidateIds('grants'))->toBe([])
        ->and($service->candidateIds('deliveries'))->toBe([])
        ->and($service->candidateIds('challenges'))->toBe([])
        ->and($service->candidateIds('rate-limits'))->toBe([]);
});

test('p0c2 concurrent reactivation prevents stale batch delete', function () {
    $user = p0c2User();
    $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
    $grant = p0c2Grant($user, $c);
    $link = p0c2Link($user, $grant, [
        'expires_at' => now()->subDays(40),
        'consumed_at' => now()->subDays(40),
    ]);

    $service = app(AkubicaOtpPruningService::class);
    $ids = $service->candidateIds('secure-links');
    expect($ids)->toContain($link->id);

    // Concurrent use: link becomes active again before delete revalidation.
    $link->update([
        'expires_at' => now()->addHour(),
        'consumed_at' => null,
        'revoked_at' => null,
        'open_count' => 1,
    ]);

    $deleted = $service->secureLinksQuery()->whereIn('id', $ids)->delete();
    expect($deleted)->toBe(0)
        ->and(OtpSecureDownloadLink::query()->find($link->id))->not->toBeNull();
});

test('p0c2 type option limits scope', function () {
    $challenge = p0c2Challenge(['expires_at' => now()->subDays(40)]);
    p0c2Delivery($challenge, ['updated_at' => now()->subDays(40)]);

    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'deliveries');

    expect($result->deliveryOperations)->toBe(1)
        ->and($result->challenges)->toBe(0)
        ->and(OtpChallenge::query()->find($challenge->id))->not->toBeNull()
        ->and(OtpDeliveryOperation::query()->count())->toBe(0);
});

test('p0c2 batch one processes all candidates', function () {
    $user = p0c2User();
    for ($i = 0; $i < 3; $i++) {
        $c = p0c2Challenge(['user_id' => $user->id, 'consumed_at' => now()->subDays(40)]);
        $g = p0c2Grant($user, $c, ['expires_at' => now()->subDays(40)]);
        p0c2Link($user, $g, ['expires_at' => now()->subDays(40), 'consumed_at' => now()->subDays(40)]);
    }

    $result = app(AkubicaOtpPruningService::class)->prune(dryRun: false, batch: 1, type: 'secure-links');
    expect($result->secureDownloadLinks)->toBe(3)
        ->and(OtpSecureDownloadLink::query()->count())->toBe(0);
});

test('p0c2 repeated force run is idempotent', function () {
    $old = p0c2Challenge(['expires_at' => now()->subDays(40)]);
    $service = app(AkubicaOtpPruningService::class);
    $first = $service->prune(dryRun: false, type: 'challenges');
    $second = $service->prune(dryRun: false, type: 'challenges');

    expect($first->challenges)->toBe(1)
        ->and($second->challenges)->toBe(0)
        ->and(OtpChallenge::query()->find($old->id))->toBeNull();
});

test('p0c2 command defaults to dry-run without force', function () {
    p0c2Challenge(['expires_at' => now()->subDays(40)]);
    $exit = Artisan::call('akubica:prune-otp');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('DRY-RUN')
        ->and(OtpChallenge::query()->count())->toBe(1);
});

test('p0c2 command force deletes and invalid options fail', function () {
    p0c2Challenge(['expires_at' => now()->subDays(40)]);
    expect(Artisan::call('akubica:prune-otp', ['--force' => true, '--type' => 'challenges']))->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(0);

    expect(Artisan::call('akubica:prune-otp', ['--type' => 'nope']))->toBe(1)
        ->and(Artisan::call('akubica:prune-otp', ['--force' => true, '--dry-run' => true]))->toBe(1)
        ->and(Artisan::call('akubica:prune-otp', ['--batch' => '0']))->toBe(1);
});

test('p0c2 command output does not leak pii otp or hashes', function () {
    $challenge = p0c2Challenge([
        'destination_normalized' => '+525511112222',
        'code_hash' => Hash::make('654321'),
        'expires_at' => now()->subDays(40),
    ]);
    p0c2Delivery($challenge, [
        'correlation_id' => (string) Str::uuid(),
        'updated_at' => now()->subDays(40),
    ]);

    Artisan::call('akubica:prune-otp', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('+525511112222')
        ->and($output)->not->toContain('654321')
        ->and($output)->not->toContain($challenge->code_hash)
        ->and($output)->not->toContain('Bearer');
});

test('p0c2 prune log summary has no sensitive fields', function () {
    Log::spy();
    p0c2Challenge(['expires_at' => now()->subDays(40)]);
    app(AkubicaOtpPruningService::class)->prune(dryRun: true, type: 'challenges');

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $event, array $context) {
            if ($event !== 'akubica_otp_prune_completed') {
                return true;
            }

            $encoded = json_encode($context);

            return ! str_contains($encoded, 'code_hash')
                && ! str_contains($encoded, 'destination')
                && ! str_contains($encoded, 'token_hash')
                && isset($context['dry_run'], $context['batch'], $context['type'], $context['duration_bucket']);
        })
        ->atLeast()
        ->once();
});

test('p0c2 scheduler is not registered when cleanup flag is off', function () {
    expect(config('otp.p0a.cleanup.enabled'))->toBeFalse();

    Artisan::call('schedule:list');
    $list = Artisan::output();

    expect($list)->not->toContain('akubica:prune-otp')
        ->and($list)->not->toContain('akubica-prune-otp');

    $console = file_get_contents(base_path('routes/console.php'));
    expect($console)->toContain("config('otp.p0a.cleanup.enabled'")
        ->and($console)->not->toContain('->onOneServer')
        ->and(substr_count($console, 'akubica:prune-otp'))->toBe(1);
});

test('p0c2 does not prune personal access tokens', function () {
    $user = p0c2User();
    $token = $user->createToken('akubica');
    $token->accessToken->forceFill(['expires_at' => now()->subDays(40)])->save();
    $patId = $token->accessToken->id;

    app(AkubicaOtpPruningService::class)->prune(dryRun: false, type: 'all');

    expect(PersonalAccessToken::query()->find($patId))->not->toBeNull();
});
