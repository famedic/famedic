<?php

use App\Enums\AkubicaRegistrationIntentInvalidationReason;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\RegistrationIntentExpiredException;
use App\Exceptions\Otp\RegistrationIntentInvalidStateException;
use App\Exceptions\Otp\RegistrationIntentPayloadException;
use App\Models\AkubicaRegistrationIntent;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\RegularAccount;
use App\Models\User;
use App\Services\Otp\Registration\AkubicaRegistrationIntentService;
use App\Services\Otp\Registration\AkubicaRegistrationPayload;
use App\Services\Otp\Registration\AkubicaRegistrationPayloadCipher;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\RegistrationIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

function p0a53Identity(
    string $email = 'intent.p0a53@ejemplo.test',
    string $phone = '5512345701',
    string $name = 'Nombre Apellido',
): RegistrationIdentity {
    return new RegistrationIdentity(
        email: app(EmailNormalizer::class)->normalize($email),
        phone: app(MexicoPhoneNormalizer::class)->normalize($phone, 'MX'),
        fullName: $name,
    );
}

beforeEach(function () {
    Notification::fake();
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
});

test('p0a53 migration creates akubica_registration_intents with expected columns', function () {
    expect(Schema::hasTable('akubica_registration_intents'))->toBeTrue();

    foreach ([
        'id',
        'otp_challenge_id',
        'status',
        'encrypted_payload',
        'payload_version',
        'email_fingerprint',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'invalidation_reason',
        'superseded_by_id',
        'created_at',
        'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('akubica_registration_intents', $column))->toBeTrue();
    }

    expect(Schema::hasColumn('akubica_registration_intents', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('akubica_registration_intents', 'phone'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'phone'))->toBeTrue();
});

test('p0a53 create pending encrypts payload and links challenge atomically', function () {
    $identity = p0a53Identity('nuevo.intent@ejemplo.test', '5512345702');
    $service = app(AkubicaRegistrationIntentService::class);

    $result = $service->createPending($identity);
    $intent = $result->intent;
    $challenge = $result->challenge;

    expect($intent->status)->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and($challenge->purpose)->toBe(P0aOtpPurpose::AkubicaRegister->value)
        ->and($challenge->destination_normalized)->toBe('+525512345702')
        ->and($challenge->destination_masked)->toBe('***5702')
        ->and($intent->otp_challenge_id)->toBe($challenge->id)
        ->and($intent->expires_at->equalTo($challenge->expires_at))->toBeTrue()
        ->and($intent->expires_at->greaterThan(now()->addMinutes(9)))->toBeTrue()
        ->and($intent->expires_at->lessThanOrEqualTo(now()->addMinutes(10)->addSecond()))->toBeTrue()
        ->and($intent->encrypted_payload)->not->toBeNull()
        ->and($intent->payload_version)->toBe(AkubicaRegistrationPayload::VERSION)
        ->and(AkubicaRegistrationPolicy::ttlMinutes())->toBe(10);

    $row = DB::table('akubica_registration_intents')->where('id', $intent->id)->first();
    expect($row->encrypted_payload)->not->toContain('nuevo.intent@ejemplo.test')
        ->and($row->encrypted_payload)->not->toContain('5512345702')
        ->and($row->encrypted_payload)->not->toContain('Nombre')
        ->and(isset($row->email))->toBeFalse();

    $read = $service->readPayload((int) $intent->id);
    expect($read->email->value())->toBe('nuevo.intent@ejemplo.test')
        ->and($read->phone->nationalNumber())->toBe('5512345702')
        ->and($read->fullName)->toBe('Nombre Apellido');

    expect($result->plainCode())->toHaveLength(6)
        ->and(User::query()->where('email', 'nuevo.intent@ejemplo.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(RegularAccount::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('p0a53 model serialization never exposes ciphertext or fingerprint', function () {
    $result = app(AkubicaRegistrationIntentService::class)
        ->createPending(p0a53Identity('serial@ejemplo.test', '5512345703'));

    $array = $result->intent->toArray();
    $json = $result->intent->toJson();

    expect($array)->not->toHaveKey('encrypted_payload')
        ->and($array)->not->toHaveKey('email_fingerprint')
        ->and($json)->not->toContain((string) $result->intent->getAttributes()['encrypted_payload'])
        ->and($json)->not->toContain('serial@ejemplo.test');
});

test('p0a53 corrupt ciphertext raises controlled payload exception without leaking', function () {
    $result = app(AkubicaRegistrationIntentService::class)
        ->createPending(p0a53Identity('corrupt@ejemplo.test', '5512345704'));

    AkubicaRegistrationIntent::query()->where('id', $result->intent->id)->update([
        'encrypted_payload' => 'not-a-valid-ciphertext',
    ]);

    try {
        app(AkubicaRegistrationIntentService::class)->readPayload((int) $result->intent->id);
        expect(false)->toBeTrue();
    } catch (RegistrationIntentPayloadException $e) {
        expect($e->errorCode)->toBe('REGISTRATION_INTENT_PAYLOAD_CORRUPT')
            ->and($e->getMessage())->not->toContain('corrupt@ejemplo.test')
            ->and($e->getMessage())->not->toContain('not-a-valid');
    }
});

test('p0a53 unknown payload version is rejected', function () {
    $cipher = app(AkubicaRegistrationPayloadCipher::class);
    $bad = Crypt::encryptString(json_encode([
        'v' => 99,
        'email' => 'x@ejemplo.test',
        'phone' => '5512345705',
        'phone_country' => 'MX',
        'full_name' => 'Nombre Apellido',
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $cipher->decrypt($bad))
        ->toThrow(RegistrationIntentPayloadException::class);
});

test('p0a53 consume erases ciphertext and blocks second consume', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $result = $service->createPending(p0a53Identity('consume@ejemplo.test', '5512345706'));
    $id = (int) $result->intent->id;

    $payload = $service->consume($id);
    expect($payload->email->value())->toBe('consume@ejemplo.test');

    $intent = AkubicaRegistrationIntent::query()->findOrFail($id);
    expect($intent->status)->toBe(AkubicaRegistrationIntentStatus::Consumed)
        ->and($intent->encrypted_payload)->toBeNull()
        ->and($intent->consumed_at)->not->toBeNull();

    expect(fn () => $service->consume($id))->toThrow(RegistrationIntentInvalidStateException::class);
    expect(fn () => $service->readPayload($id))->toThrow(RegistrationIntentInvalidStateException::class);
});

test('p0a53 expire and invalidate erase ciphertext and are idempotent', function () {
    $service = app(AkubicaRegistrationIntentService::class);

    $a = $service->createPending(p0a53Identity('expire@ejemplo.test', '5512345707'));
    $service->expire((int) $a->intent->id);
    $service->expire((int) $a->intent->id);
    $expired = AkubicaRegistrationIntent::query()->findOrFail($a->intent->id);
    expect($expired->status)->toBe(AkubicaRegistrationIntentStatus::Expired)
        ->and($expired->encrypted_payload)->toBeNull();

    $b = $service->createPending(p0a53Identity('invalidate@ejemplo.test', '5512345708'));
    $service->invalidate((int) $b->intent->id, AkubicaRegistrationIntentInvalidationReason::Manual);
    $service->invalidate((int) $b->intent->id, AkubicaRegistrationIntentInvalidationReason::Manual);
    $invalid = AkubicaRegistrationIntent::query()->findOrFail($b->intent->id);
    expect($invalid->status)->toBe(AkubicaRegistrationIntentStatus::Invalidated)
        ->and($invalid->encrypted_payload)->toBeNull()
        ->and($invalid->invalidation_reason)->toBe(AkubicaRegistrationIntentInvalidationReason::Manual);
});

test('p0a53 expired pending cannot be consumed', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $result = $service->createPending(p0a53Identity('past@ejemplo.test', '5512345709'));

    AkubicaRegistrationIntent::query()->where('id', $result->intent->id)->update([
        'expires_at' => now()->subMinute(),
    ]);
    OtpChallenge::query()->where('id', $result->challenge->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => $service->consume((int) $result->intent->id))
        ->toThrow(RegistrationIntentExpiredException::class);

    expect(AkubicaRegistrationIntent::query()->find($result->intent->id)->encrypted_payload)
        ->not->toBeNull();
});

test('p0a53 second create for same email supersedes previous pending', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $first = $service->createPending(p0a53Identity('same.email@ejemplo.test', '5512345710'));
    $this->travel(61)->seconds();
    $second = $service->createPending(p0a53Identity('same.email@ejemplo.test', '5512345711'));

    $old = AkubicaRegistrationIntent::query()->findOrFail($first->intent->id);
    $new = AkubicaRegistrationIntent::query()->findOrFail($second->intent->id);

    expect($old->status)->toBe(AkubicaRegistrationIntentStatus::Superseded)
        ->and($old->encrypted_payload)->toBeNull()
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and($new->status)->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and($new->encrypted_payload)->not->toBeNull()
        ->and(fn () => $service->readPayload((int) $old->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);
});

test('p0a53 otp_challenge_id association is unique', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $result = $service->createPending(p0a53Identity('unique.ch@ejemplo.test', '5512345712'));

    expect(fn () => AkubicaRegistrationIntent::query()->create([
        'otp_challenge_id' => $result->challenge->id,
        'status' => AkubicaRegistrationIntentStatus::Pending,
        'encrypted_payload' => null,
        'payload_version' => 1,
        'email_fingerprint' => hash('sha256', 'other'),
        'expires_at' => now()->addMinutes(10),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('p0a53 create rolls back challenge when intent persistence fails', function () {
    $beforeChallenges = OtpChallenge::query()->count();
    $beforeIntents = AkubicaRegistrationIntent::query()->count();

    $identity = p0a53Identity('rollback@ejemplo.test', '5512345713');

    try {
        DB::transaction(function () use ($identity) {
            $service = app(AkubicaRegistrationIntentService::class);
            $service->createPending($identity);
            throw new RuntimeException('force-rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OtpChallenge::query()->count())->toBe($beforeChallenges)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe($beforeIntents)
        ->and(User::query()->where('email', 'rollback@ejemplo.test')->exists())->toBeFalse();
});

test('p0a53 expireDuePending cleanup is selective and idempotent', function () {
    $service = app(AkubicaRegistrationIntentService::class);

    $due = $service->createPending(p0a53Identity('due@ejemplo.test', '5512345714'));
    $alive = $service->createPending(p0a53Identity('alive@ejemplo.test', '5512345715'));
    $consumed = $service->createPending(p0a53Identity('done@ejemplo.test', '5512345716'));
    $service->consume((int) $consumed->intent->id);

    AkubicaRegistrationIntent::query()->where('id', $due->intent->id)->update([
        'expires_at' => now()->subMinutes(2),
    ]);

    $n1 = $service->expireDuePending();
    $n2 = $service->expireDuePending();

    expect($n1)->toBe(1)
        ->and($n2)->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->find($due->intent->id)->status)
        ->toBe(AkubicaRegistrationIntentStatus::Expired)
        ->and(AkubicaRegistrationIntent::query()->find($due->intent->id)->encrypted_payload)->toBeNull()
        ->and(AkubicaRegistrationIntent::query()->find($alive->intent->id)->status)
        ->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and(AkubicaRegistrationIntent::query()->find($alive->intent->id)->encrypted_payload)->not->toBeNull()
        ->and(AkubicaRegistrationIntent::query()->find($consumed->intent->id)->status)
        ->toBe(AkubicaRegistrationIntentStatus::Consumed);

    Artisan::call('akubica:expire-registration-intents');
    expect(Artisan::output())->toContain('0');
});

test('p0a53 flags remain off and patient ready stays false', function () {
    expect(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(config('sanctum.expiration'))->toBe(1440);
});

test('p0a53 challenge relation is available on models', function () {
    $result = app(AkubicaRegistrationIntentService::class)
        ->createPending(p0a53Identity('rel@ejemplo.test', '5512345717'));

    expect($result->intent->otpChallenge->id)->toBe($result->challenge->id)
        ->and($result->challenge->registrationIntent->id)->toBe($result->intent->id);
});

test('p0a53 read and consume reject incoherent challenges', function () {
    $service = app(AkubicaRegistrationIntentService::class);

    $wrongPurpose = $service->createPending(p0a53Identity('wp@ejemplo.test', '5512345718'));
    OtpChallenge::query()->where('id', $wrongPurpose->challenge->id)->update(['purpose' => 'akubica_login']);
    expect(fn () => $service->readPayload((int) $wrongPurpose->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);

    $consumedCh = $service->createPending(p0a53Identity('cc@ejemplo.test', '5512345719'));
    OtpChallenge::query()->where('id', $consumedCh->challenge->id)->update(['consumed_at' => now()]);
    expect(fn () => $service->consume((int) $consumedCh->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);

    $invalidCh = $service->createPending(p0a53Identity('ic@ejemplo.test', '5512345720'));
    OtpChallenge::query()->where('id', $invalidCh->challenge->id)->update([
        'invalidated_at' => now(),
        'invalidated_reason' => 'superseded',
    ]);
    expect(fn () => $service->readPayload((int) $invalidCh->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);

    $expiredCh = $service->createPending(p0a53Identity('ec@ejemplo.test', '5512345721'));
    OtpChallenge::query()->where('id', $expiredCh->challenge->id)->update([
        'expires_at' => now()->subMinute(),
    ]);
    // Intent row still PENDING with future expires_at â€” challenge governs.
    expect(fn () => $service->readPayload((int) $expiredCh->intent->id))
        ->toThrow(RegistrationIntentExpiredException::class);
});

test('p0a53 divergent intent expires_at still blocked when challenge already past', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $result = $service->createPending(p0a53Identity('div@ejemplo.test', '5512345722'));

    OtpChallenge::query()->where('id', $result->challenge->id)->update([
        'expires_at' => now()->subMinutes(2),
    ]);
    AkubicaRegistrationIntent::query()->where('id', $result->intent->id)->update([
        'expires_at' => now()->addMinutes(10),
    ]);

    expect(fn () => $service->consume((int) $result->intent->id))
        ->toThrow(RegistrationIntentExpiredException::class);
});

test('p0a53 different emails same phone can both stay pending', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $a = $service->createPending(p0a53Identity('phone.a@ejemplo.test', '5512345799'));
    $b = $service->createPending(p0a53Identity('phone.b@ejemplo.test', '5512345799'));

    expect($a->intent->status)->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and($b->intent->status)->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and($a->intent->encrypted_payload)->not->toBeNull()
        ->and($b->intent->encrypted_payload)->not->toBeNull();
});

test('p0a53 payload rejects missing and unknown fields without leaking pii', function () {
    $cipher = app(AkubicaRegistrationPayloadCipher::class);

    $missing = Crypt::encryptString(json_encode([
        'v' => 1,
        'email' => 'miss@ejemplo.test',
        'phone' => '5512345723',
        'phone_country' => 'MX',
    ], JSON_THROW_ON_ERROR));

    try {
        $cipher->decrypt($missing);
        expect(false)->toBeTrue();
    } catch (RegistrationIntentPayloadException $e) {
        expect($e->getMessage())->not->toContain('miss@ejemplo.test')
            ->and($e->getMessage())->not->toContain('5512345723');
    }

    $unknown = Crypt::encryptString(json_encode([
        'v' => 1,
        'email' => 'unk@ejemplo.test',
        'phone' => '5512345724',
        'phone_country' => 'MX',
        'full_name' => 'Nombre Apellido',
        'extra' => 'nope',
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $cipher->decrypt($unknown))
        ->toThrow(RegistrationIntentPayloadException::class);
});

test('p0a53 terminal transitions clear ciphertext and reject illegal follow-ups', function () {
    $service = app(AkubicaRegistrationIntentService::class);

    $consumed = $service->createPending(p0a53Identity('term.c@ejemplo.test', '5512345725'));
    $service->consume((int) $consumed->intent->id);
    expect(fn () => $service->expire((int) $consumed->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);
    expect(DB::table('akubica_registration_intents')->where('id', $consumed->intent->id)->value('encrypted_payload'))
        ->toBeNull();

    $expired = $service->createPending(p0a53Identity('term.e@ejemplo.test', '5512345726'));
    $service->expire((int) $expired->intent->id);
    expect(fn () => $service->invalidate(
        (int) $expired->intent->id,
        AkubicaRegistrationIntentInvalidationReason::Manual,
    ))->toThrow(RegistrationIntentInvalidStateException::class);
    expect(DB::table('akubica_registration_intents')->where('id', $expired->intent->id)->value('encrypted_payload'))
        ->toBeNull();

    $first = $service->createPending(p0a53Identity('term.s@ejemplo.test', '5512345727'));
    $this->travel(61)->seconds();
    $service->createPending(p0a53Identity('term.s@ejemplo.test', '5512345728'));
    expect(fn () => $service->consume((int) $first->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);
    expect(DB::table('akubica_registration_intents')->where('id', $first->intent->id)->value('encrypted_payload'))
        ->toBeNull()
        ->and(DB::table('akubica_registration_intents')->where('id', $first->intent->id)->value('status'))
        ->toBe(AkubicaRegistrationIntentStatus::Superseded->value);

    // Terminal cannot return to PENDING via mass update path used by service.
    expect(fn () => $service->readPayload((int) $expired->intent->id))
        ->toThrow(RegistrationIntentInvalidStateException::class);
});

test('p0a53 expire command exit code and does not touch terminal rows', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $due = $service->createPending(p0a53Identity('cmd.due@ejemplo.test', '5512345729'));
    $kept = $service->createPending(p0a53Identity('cmd.ok@ejemplo.test', '5512345730'));
    $service->consume((int) $kept->intent->id);

    AkubicaRegistrationIntent::query()->where('id', $due->intent->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $exit = Artisan::call('akubica:expire-registration-intents');
    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('1')
        ->and(AkubicaRegistrationIntent::query()->find($due->intent->id)->status)
        ->toBe(AkubicaRegistrationIntentStatus::Expired)
        ->and(AkubicaRegistrationIntent::query()->find($kept->intent->id)->status)
        ->toBe(AkubicaRegistrationIntentStatus::Consumed);

    Notification::assertNothingSent();
});

test('p0a53 expired pending cannot be read', function () {
    $service = app(AkubicaRegistrationIntentService::class);
    $result = $service->createPending(p0a53Identity('noread@ejemplo.test', '5512345731'));

    AkubicaRegistrationIntent::query()->where('id', $result->intent->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => $service->readPayload((int) $result->intent->id))
        ->toThrow(RegistrationIntentExpiredException::class);
});
