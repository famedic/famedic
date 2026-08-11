<?php

use App\Console\Commands\MaterializeAkubicaUatFixturesCommand;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Exceptions\AkubicaUatFixtureException;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Api\V1\IdempotencyRecord;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\RegularAccount;
use App\Models\User;
use App\Services\Api\V1\Uat\AkubicaUatFixtureMaterializer;
use App\Data\Api\V1\Uat\AkubicaUatFixturePlan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function eulU01Identities(): array
{
    return [
        'primary' => ['email' => 'akubica-uat-primary@example.invalid', 'phone' => '8110000001', 'country' => 'MX'],
        'foreign' => ['email' => 'akubica-uat-foreign@example.invalid', 'phone' => '8110000002', 'country' => 'MX'],
        'disposable' => ['email' => 'akubica-uat-disposable@example.invalid', 'phone' => '8110000003', 'country' => 'MX'],
    ];
}

function eulU01ConfigureIdentities(?array $override = null): void
{
    foreach ($override ?? eulU01Identities() as $role => $identity) {
        config()->set("akubica_uat.identities.{$role}.email", $identity['email'] ?? null);
        config()->set("akubica_uat.identities.{$role}.phone", $identity['phone'] ?? null);
        config()->set("akubica_uat.identities.{$role}.country", $identity['country'] ?? null);
    }
}

function eulU01Apply($test): AkubicaUatFixtureManifest
{
    $test->artisan('akubica:uat-fixtures', [
        '--apply' => true,
        '--confirm' => AkubicaUatFixtureContract::NAMESPACE,
    ])->assertSuccessful();

    return AkubicaUatFixtureManifest::query()->firstOrFail();
}

function eulU01Ids(): array
{
    return AkubicaUatFixtureManifest::query()->firstOrFail()->metadata['ids'];
}

function eulU01AllowedIdempotencyOperation(string $path = 'api/v1/auth/login/request-code'): array
{
    return ['method' => 'POST', 'path' => $path];
}

function eulU01AllowedIdempotencyOperations(): array
{
    return [
        eulU01AllowedIdempotencyOperation('api/v1/auth/login/request-code'),
        eulU01AllowedIdempotencyOperation('api/v1/auth/register'),
        eulU01AllowedIdempotencyOperation('api/v1/checkout/payment-link'),
        eulU01AllowedIdempotencyOperation('api/v1/laboratory-appointments'),
        eulU01AllowedIdempotencyOperation('api/v1/orders/{order_id}/invoices/{invoice_id}/step-up/request'),
        eulU01AllowedIdempotencyOperation('api/v1/orders/{order_id}/invoices/{invoice_id}/secure-link'),
        eulU01AllowedIdempotencyOperation('api/v1/orders/{order_id}/results/step-up/request'),
        eulU01AllowedIdempotencyOperation('api/v1/orders/{order_id}/results/secure-link'),
        eulU01AllowedIdempotencyOperation('api/v1/orders/{order_id}/invoice-request'),
    ];
}

function eulU01CreateIdempotencyRecord(array $overrides = []): IdempotencyRecord
{
    return IdempotencyRecord::query()->create(array_merge([
        'actor_key' => str_repeat('a', 64),
        'method' => 'POST',
        'path' => 'api/v1/auth/login/request-code',
        'key_hash' => str_repeat('b', 64),
        'request_hash' => str_repeat('c', 64),
        'status' => 'succeeded',
        'http_status' => 200,
        'response_body' => null,
        'response_headers' => null,
        'correlation_id' => 'eul-u01-correlation',
        'lease_expires_at' => null,
        'expires_at' => now()->addHour(),
    ], $overrides));
}

function eulU01AttachIdempotencyMetadata(AkubicaUatFixtureManifest $manifest, array $recordIds, array $overrides = []): AkubicaUatFixtureManifest
{
    $metadata = $manifest->metadata;

    $metadata = array_merge($metadata, [
        'idempotency_record_ids' => $recordIds,
        'idempotency_allowed_operations' => eulU01AllowedIdempotencyOperations(),
        'idempotency_window_started_at' => now()->subMinutes(5)->toIso8601String(),
        'idempotency_window_ended_at' => now()->addMinute()->toIso8601String(),
    ], $overrides);

    $manifest->forceFill(['metadata' => $metadata])->save();

    return $manifest->fresh();
}

beforeEach(function () {
    eulU01ConfigureIdentities();
    Storage::fake(AkubicaUatFixtureContract::STORAGE_DISK);
});

test('command is registered explicitly through Laravel bootstrap', function () {
    expect(app('Illuminate\Contracts\Console\Kernel')->all())
        ->toHaveKey('akubica:uat-fixtures')
        ->and(app('Illuminate\Contracts\Console\Kernel')->all()['akubica:uat-fixtures'])
        ->toBeInstanceOf(MaterializeAkubicaUatFixturesCommand::class);
});

test('production is blocked before resolving the materializer', function () {
    $this->app->detectEnvironment(fn () => 'production');
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => throw new RuntimeException('materializer resolved'));

    $this->artisan('akubica:uat-fixtures')->assertFailed();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(0);
});

test('unknown environment is blocked before materializer database or storage', function () {
    $this->app->detectEnvironment(fn () => 'qa-unknown');
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => throw new RuntimeException('materializer resolved'));

    DB::shouldReceive('connection')->never();
    Storage::shouldReceive('disk')->never();

    $this->artisan('akubica:uat-fixtures')
        ->expectsOutputToContain('UAT_ENVIRONMENT_NOT_ALLOWED')
        ->doesntExpectOutputToContain('qa-unknown')
        ->assertFailed();
});

test('invalid namespace is rejected before materializer db cache lock or storage', function () {
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => throw new RuntimeException('materializer resolved'));

    $this->artisan('akubica:uat-fixtures', ['--namespace' => 'feature/apis-akubica'])
        ->expectsOutputToContain('UAT_NAMESPACE_NOT_ALLOWED')
        ->assertFailed();

    expect(Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->allFiles())->toBe([]);
});

test('invalid confirmation is rejected before materializer resolution', function () {
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => throw new RuntimeException('materializer resolved'));

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => 'mismatch'])
        ->expectsOutputToContain('UAT_CONFIRMATION_MISMATCH')
        ->assertFailed();
});

test('apply and reset are mutually exclusive', function () {
    $this->artisan('akubica:uat-fixtures', [
        '--apply' => true,
        '--reset' => true,
        '--confirm' => AkubicaUatFixtureContract::NAMESPACE,
    ])->expectsOutputToContain('UAT_INVALID_OPTION_COMBINATION')->assertFailed();
});

test('dry run is pure and succeeds with missing identities', function () {
    eulU01ConfigureIdentities([
        'primary' => ['email' => null, 'phone' => null, 'country' => null],
        'foreign' => ['email' => null, 'phone' => null, 'country' => null],
        'disposable' => ['email' => null, 'phone' => null, 'country' => null],
    ]);
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => throw new RuntimeException('materializer resolved'));

    $this->artisan('akubica:uat-fixtures')
        ->expectsOutputToContain('"primary":"not_configured"')
        ->doesntExpectOutputToContain('true')
        ->doesntExpectOutputToContain('false')
        ->doesntExpectOutputToContain('akubica-uat-primary@example.invalid')
        ->doesntExpectOutputToContain('8110000001')
        ->assertSuccessful();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(0)
        ->and(Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->allFiles())->toBe([]);
});

test('apply requires complete configured identities', function () {
    eulU01ConfigureIdentities([
        'primary' => ['email' => null, 'phone' => null, 'country' => null],
        'foreign' => eulU01Identities()['foreign'],
        'disposable' => eulU01Identities()['disposable'],
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_CONFIG_REQUIRED')
        ->assertFailed();
});

test('identity collision is rejected without manifest writes', function () {
    $identity = eulU01Identities()['primary'];
    User::factory()->create([
        'email' => $identity['email'],
        'phone' => $identity['phone'],
        'phone_country' => $identity['country'],
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_USER')
        ->assertFailed();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(0);
});

test('customer and contact collisions are rejected before writes', function () {
    $user = User::factory()->create();
    $account = RegularAccount::query()->create([]);
    Customer::query()->create(['user_id' => $user->id, 'customerable_type' => RegularAccount::class, 'customerable_id' => $account->id]);
    Contact::query()->create([
        'customer_id' => Customer::query()->firstOrFail()->id,
        'name' => '[UAT] Contacto primary',
        'paternal_lastname' => 'PRIMARY',
        'maternal_lastname' => 'AKUBICA',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'phone' => '8110000009',
        'phone_country' => 'MX',
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_CONTACT')
        ->assertFailed();
});

test('apply creates active manifest with no pii in metadata', function () {
    $manifest = eulU01Apply($this);

    expect($manifest->status)->toBe(AkubicaUatFixtureContract::STATUS_ACTIVE)
        ->and(json_encode($manifest->metadata))->not->toContain(eulU01Identities()['primary']['email'])
        ->and(json_encode($manifest->metadata))->not->toContain(eulU01Identities()['primary']['phone']);
});

test('apply materializes expected top level records through manifest ids', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();

    expect(User::query()->whereIn('id', array_values($ids['users']))->count())->toBe(2)
        ->and(Customer::query()->whereIn('id', array_values($ids['customers']))->count())->toBe(2)
        ->and(LaboratoryPurchase::query()->whereIn('id', array_values($ids['purchases']))->count())->toBe(5)
        ->and(Coupon::query()->whereIn('id', array_values($ids['coupons']))->count())->toBe(3)
        ->and(LaboratoryTest::query()->whereIn('id', array_values($ids['tests']))->count())->toBe(2)
        ->and(LaboratoryStore::query()->where('id', $ids['store'])->count())->toBe(1)
        ->and(LaboratoryTestCategory::query()->where('id', $ids['category'])->count())->toBe(1);
});

test('purchase item payload includes required indications and excludes nonexistent purchase coupon id', function () {
    eulU01Apply($this);
    $item = LaboratoryPurchaseItem::query()->firstOrFail();
    $purchase = LaboratoryPurchase::query()->firstOrFail();

    expect($item->indications)->not->toBeNull()
        ->and($purchase->getAttributes())->not->toHaveKey('coupon_id');
});

test('storage uses local disk and exact allowlisted hashes', function () {
    $manifest = eulU01Apply($this);

    foreach ($manifest->metadata['storage_hashes'] as $path => $hash) {
        expect($path)->toStartWith(AkubicaUatFixtureContract::STORAGE_PREFIX)
            ->and(str_contains($path, '..'))->toBeFalse()
            ->and(str_contains($path, '\\'))->toBeFalse()
            ->and(Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->exists($path))->toBeTrue()
            ->and(hash('sha256', Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->get($path)))->toBe($hash);
    }
});

test('second apply preserves subordinate ids and reports zero created', function () {
    eulU01Apply($this);
    $firstIds = eulU01Ids();

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('"created":0')
        ->assertSuccessful();

    expect(eulU01Ids()['cart_items'])->toBe($firstIds['cart_items'])
        ->and(eulU01Ids()['purchase_items'])->toBe($firstIds['purchase_items'])
        ->and(eulU01Ids()['invoices'])->toBe($firstIds['invoices'])
        ->and(eulU01Ids()['invoice_requests'])->toBe($firstIds['invoice_requests']);
});

test('ready and pending result scenarios are materialized', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();

    expect(LaboratoryPurchase::query()->findOrFail($ids['purchases']['results_ready'])->results)->not->toBeNull()
        ->and(LaboratoryPurchase::query()->findOrFail($ids['purchases']['results_pending'])->results)->toBeNull();
});

test('invoice ready and invoice request scenarios are materialized', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();

    expect(Invoice::query()->where('id', $ids['invoices']['invoice_ready'])->exists())->toBeTrue()
        ->and(InvoiceRequest::query()->where('id', $ids['invoice_requests']['invoice_request_pending'])->exists())->toBeTrue();
});

test('foreign order belongs to foreign customer from manifest ids', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();

    expect(LaboratoryPurchase::query()->findOrFail($ids['purchases']['foreign_order'])->customer_id)
        ->toBe($ids['customers']['foreign']);
});

test('cart and checkout draft use deterministic manifest ids', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();

    expect(LaboratoryCartItem::query()->whereIn('id', $ids['cart_items'])->count())->toBe(2)
        ->and($ids['checkout_draft'])->toBeInt();
});

test('coupon scenarios include valid used not applicable and reserved missing hash only', function () {
    $manifest = eulU01Apply($this);
    $ids = $manifest->metadata['ids'];

    expect(Coupon::query()->whereIn('id', array_values($ids['coupons']))->count())->toBe(3)
        ->and($manifest->metadata)->toHaveKey('reserved_nonexistent_coupon_hash')
        ->and(json_encode($manifest->metadata))->not->toContain('AKUATV1MISSING');
});

test('queue bus mail notifications and http stay neutralized during apply', function () {
    Queue::fake();
    Bus::fake();
    Mail::fake();
    Notification::fake();
    Http::preventStrayRequests();

    eulU01Apply($this);

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('sms vonage gda scout and payment actions are not resolved by the materializer path', function () {
    foreach ([
        App\Services\Otp\Delivery\VonageOtpDeliveryProvider::class,
        App\Actions\Laboratories\CreateGDAQuotationAction::class,
        App\Actions\Laboratories\CreateGDAOnlyQuotationAction::class,
        App\Actions\Laboratories\GetGDAResultsAction::class,
        App\Actions\Stripe\FindOrCreateStripeCustomerAction::class,
    ] as $abstract) {
        app()->bind($abstract, fn () => throw new RuntimeException($abstract.' resolved'));
    }

    eulU01Apply($this);

    expect(config('otp.p0a.delivery.driver'))->toBe('fake')
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('scout.driver'))->toBe('null');
});

test('reset removes only verified namespace fixtures and storage', function () {
    eulU01Apply($this);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->assertSuccessful();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->allFiles())->toBe([]);
});

test('second reset is no op', function () {
    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->assertSuccessful();
});

test('reset without idempotency ids preserves same actor records', function () {
    $manifest = eulU01Apply($this);
    $actorHash = $manifest->metadata['idempotency_actor_hashes']['primary'];
    $record = eulU01CreateIdempotencyRecord(['actor_key' => $actorHash]);
    eulU01AttachIdempotencyMetadata($manifest, []);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('not_recorded')
        ->assertSuccessful();

    expect(IdempotencyRecord::query()->whereKey($record->id)->exists())->toBeTrue();
});

test('reset deletes only listed verified idempotency records', function () {
    $manifest = eulU01Apply($this);
    $actorHash = $manifest->metadata['idempotency_actor_hashes']['primary'];
    $listed = eulU01CreateIdempotencyRecord(['actor_key' => $actorHash]);
    $unlisted = eulU01CreateIdempotencyRecord([
        'actor_key' => $actorHash,
        'key_hash' => str_repeat('d', 64),
        'request_hash' => str_repeat('e', 64),
        'correlation_id' => 'eul-u01-correlation-unlisted',
    ]);
    eulU01AttachIdempotencyMetadata($manifest, [$listed->id]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('verified_ids')
        ->assertSuccessful();

    expect(IdempotencyRecord::query()->whereKey($listed->id)->exists())->toBeFalse()
        ->and(IdempotencyRecord::query()->whereKey($unlisted->id)->exists())->toBeTrue();
});

test('reset aborts when listed idempotency record has wrong path', function () {
    $manifest = eulU01Apply($this);
    $record = eulU01CreateIdempotencyRecord([
        'actor_key' => $manifest->metadata['idempotency_actor_hashes']['primary'],
        'path' => 'api/v1/cart/items',
    ]);
    eulU01AttachIdempotencyMetadata($manifest, [$record->id]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_IDEMPOTENCY_MISMATCH')
        ->assertFailed();

    expect(IdempotencyRecord::query()->whereKey($record->id)->exists())->toBeTrue()
        ->and(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts when listed idempotency record is outside recorded window', function () {
    $manifest = eulU01Apply($this);
    $record = eulU01CreateIdempotencyRecord([
        'actor_key' => $manifest->metadata['idempotency_actor_hashes']['primary'],
    ]);
    $record->forceFill([
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ])->save();
    eulU01AttachIdempotencyMetadata($manifest, [$record->id], [
        'idempotency_window_started_at' => now()->subMinute()->toIso8601String(),
        'idempotency_window_ended_at' => now()->addMinute()->toIso8601String(),
    ]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_IDEMPOTENCY_MISMATCH')
        ->assertFailed();

    expect(IdempotencyRecord::query()->whereKey($record->id)->exists())->toBeTrue()
        ->and(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts when listed idempotency record has different actor', function () {
    $manifest = eulU01Apply($this);
    $record = eulU01CreateIdempotencyRecord(['actor_key' => str_repeat('f', 64)]);
    eulU01AttachIdempotencyMetadata($manifest, [$record->id]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_IDEMPOTENCY_MISMATCH')
        ->assertFailed();

    expect(IdempotencyRecord::query()->whereKey($record->id)->exists())->toBeTrue()
        ->and(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts when listed idempotency record does not exist', function () {
    $manifest = eulU01Apply($this);
    $missingId = (int) IdempotencyRecord::query()->max('id') + 1000;
    eulU01AttachIdempotencyMetadata($manifest, [$missingId]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_IDEMPOTENCY_MISMATCH')
        ->assertFailed();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts atomically when one listed idempotency record diverges', function () {
    $manifest = eulU01Apply($this);
    $actorHash = $manifest->metadata['idempotency_actor_hashes']['primary'];
    $valid = eulU01CreateIdempotencyRecord(['actor_key' => $actorHash]);
    $divergent = eulU01CreateIdempotencyRecord([
        'actor_key' => $actorHash,
        'path' => 'api/v1/cart/items',
        'key_hash' => str_repeat('d', 64),
        'request_hash' => str_repeat('e', 64),
        'correlation_id' => 'eul-u01-correlation-divergent',
    ]);
    eulU01AttachIdempotencyMetadata($manifest, [$valid->id, $divergent->id]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_IDEMPOTENCY_MISMATCH')
        ->assertFailed();

    expect(IdempotencyRecord::query()->whereKey($valid->id)->exists())->toBeTrue()
        ->and(IdempotencyRecord::query()->whereKey($divergent->id)->exists())->toBeTrue()
        ->and(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts when manifest id points to foreign user', function () {
    $manifest = eulU01Apply($this);
    $metadata = $manifest->metadata;
    $metadata['ids']['users']['primary'] = User::factory()->create()->id;
    $manifest->forceFill(['metadata' => $metadata])->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_USER_MISMATCH')
        ->assertFailed();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(1);
});

test('reset aborts when natural key has been altered', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();
    LaboratoryPurchase::query()->findOrFail($ids['purchases']['results_ready'])->forceFill(['gda_order_id' => 'FOREIGN'])->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_PURCHASE_MISMATCH')
        ->assertFailed();
});

test('reset aborts when parent relation has been altered', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();
    Contact::query()->findOrFail($ids['contacts']['primary'])->forceFill(['customer_id' => $ids['customers']['foreign']])->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_PARENT_MISMATCH')
        ->assertFailed();
});

test('reset aborts when external reference exists', function () {
    eulU01Apply($this);
    $ids = eulU01Ids();
    CouponUser::query()->create([
        'coupon_id' => $ids['coupons']['valid'],
        'user_id' => $ids['users']['foreign'],
        'assigned_at' => now(),
    ]);

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_EXTERNAL_REFERENCE')
        ->assertFailed();
});

test('reset aborts when metadata is corrupt', function () {
    $manifest = eulU01Apply($this);
    $manifest->forceFill(['metadata' => ['ids' => 'corrupt']])->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_MANIFEST_CORRUPT')
        ->assertFailed();
});

test('storage hash mismatch blocks reset', function () {
    $manifest = eulU01Apply($this);
    $path = array_key_first($manifest->metadata['storage_hashes']);
    Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->put($path, 'changed');

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_STORAGE_HASH_MISMATCH')
        ->assertFailed();
});

test('storage collision with different hash blocks apply', function () {
    Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->put(
        AkubicaUatFixtureContract::storagePath('results/result-ready.pdf'),
        '%PDF-1.4 foreign'
    );

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_STORAGE')
        ->assertFailed();
});

test('rollback leaves no active manifest when materializer throws before persistence', function () {
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => new class extends AkubicaUatFixtureMaterializer
    {
        public function assertNoCollisions(AkubicaUatFixturePlan $plan): void
        {
            throw new AkubicaUatFixtureException('UAT_TEST_FAILURE');
        }
    });

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_TEST_FAILURE')
        ->assertFailed();

    expect(AkubicaUatFixtureManifest::query()->count())->toBe(0);
});

test('foreign invoice request is registered in manifest', function () {
    $manifest = eulU01Apply($this);

    expect($manifest->metadata['ids']['invoice_requests'])
        ->toHaveKey('invoice_request_pending')
        ->toHaveKey('foreign_order_invoice_request')
        ->and($manifest->metadata['logical_types']['invoice_requests']['foreign_order_invoice_request'])
        ->toBe('foreign_order_invoice_request');
});

test('foreign invoice request is reset with the fixture set', function () {
    $manifest = eulU01Apply($this);
    $foreignInvoiceRequestId = $manifest->metadata['ids']['invoice_requests']['foreign_order_invoice_request'];

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->assertSuccessful();

    expect(InvoiceRequest::query()->withTrashed()->find($foreignInvoiceRequestId))->toBeNull();
});

test('foreign invoice request parent alteration aborts reset', function () {
    $manifest = eulU01Apply($this);
    $ids = $manifest->metadata['ids'];
    InvoiceRequest::query()
        ->findOrFail($ids['invoice_requests']['foreign_order_invoice_request'])
        ->forceFill(['invoice_requestable_id' => $ids['purchases']['invoice_request_pending']])
        ->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_RESET_INVOICE_REQUEST_MISMATCH')
        ->assertFailed();
});

test('manifest missing one invoice request is corrupt', function () {
    $manifest = eulU01Apply($this);
    $metadata = $manifest->metadata;
    unset($metadata['ids']['invoice_requests']['foreign_order_invoice_request']);
    $manifest->forceFill(['metadata' => $metadata])->save();

    $this->artisan('akubica:uat-fixtures', ['--reset' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_MANIFEST_CORRUPT')
        ->assertFailed();
});

test('failure after preparing manifest leaves failed recoverable metadata', function () {
    config()->set('akubica_uat.testing_faults', ['after_preparing_manifest']);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_TESTING_FAULT_after_preparing_manifest')
        ->assertFailed();

    $manifest = AkubicaUatFixtureManifest::query()->firstOrFail();
    expect($manifest->status)->toBe(AkubicaUatFixtureContract::STATUS_FAILED)
        ->and($manifest->metadata)->toHaveKey('run_id_hash')
        ->and($manifest->metadata)->toHaveKey('storage_hashes')
        ->and($manifest->metadata)->toHaveKey('natural_key_hashes');
});

test('failed manifest can be retried when metadata is still valid', function () {
    config()->set('akubica_uat.testing_faults', ['after_preparing_manifest']);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])->assertFailed();

    config()->set('akubica_uat.testing_faults', []);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->assertSuccessful();

    expect(AkubicaUatFixtureManifest::query()->firstOrFail()->status)->toBe(AkubicaUatFixtureContract::STATUS_ACTIVE);
});

test('recovery after promotion completes active when hashes match', function () {
    config()->set('akubica_uat.testing_faults', ['after_hash_before_active']);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])->assertFailed();

    config()->set('akubica_uat.testing_faults', []);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->assertSuccessful();

    expect(AkubicaUatFixtureManifest::query()->firstOrFail()->status)->toBe(AkubicaUatFixtureContract::STATUS_ACTIVE);
});

test('final hash divergence blocks recovery', function () {
    config()->set('akubica_uat.testing_faults', ['after_hash_before_active']);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])->assertFailed();
    Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->put(AkubicaUatFixtureContract::storagePath('results/result-ready.pdf'), '%PDF-1.4 changed');

    config()->set('akubica_uat.testing_faults', []);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_STORAGE_HASH_MISMATCH')
        ->assertFailed();
});

test('failed manifest with foreign divergence is not adopted', function () {
    config()->set('akubica_uat.testing_faults', ['after_preparing_manifest']);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])->assertFailed();
    $manifest = AkubicaUatFixtureManifest::query()->firstOrFail();
    $metadata = $manifest->metadata;
    $metadata['natural_key_hashes']['category'] = hash('sha256', 'foreign');
    $manifest->forceFill(['metadata' => $metadata])->save();

    config()->set('akubica_uat.testing_faults', []);
    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_MANIFEST_CORRUPT')
        ->assertFailed();
});

test('regular account collision without manifest aborts', function () {
    $identity = eulU01Identities()['primary'];
    $user = User::factory()->create(['email' => $identity['email'], 'phone' => $identity['phone'], 'phone_country' => $identity['country']]);
    $account = RegularAccount::query()->create([]);
    Customer::query()->create([
        'user_id' => $user->id,
        'medical_attention_identifier' => AkubicaUatFixtureContract::NAMESPACE.'-customer-primary',
        'customerable_type' => RegularAccount::class,
        'customerable_id' => $account->id,
    ]);
    User::query()->whereKey($user->id)->update(['email' => 'changed@example.invalid', 'phone' => '8110000099']);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_REGULAR_ACCOUNT')
        ->assertFailed();
});

test('customer collision without manifest aborts', function () {
    $user = User::factory()->create();
    $account = RegularAccount::query()->create([]);
    Customer::query()->create([
        'user_id' => $user->id,
        'medical_attention_identifier' => 'foreign-customer-with-regular-account',
        'customerable_type' => RegularAccount::class,
        'customerable_id' => $account->id,
    ]);

    Customer::query()->create([
        'user_id' => $user->id,
        'medical_attention_identifier' => AkubicaUatFixtureContract::NAMESPACE.'-customer-primary',
        'customerable_type' => RegularAccount::class,
        'customerable_id' => $account->id + 1000,
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_CUSTOMER')
        ->assertFailed();
});

test('invoice collision without manifest aborts', function () {
    Invoice::query()->create([
        'invoiceable_type' => User::class,
        'invoiceable_id' => 999,
        'invoice' => AkubicaUatFixtureContract::storagePath('invoices/invoice-ready.pdf'),
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_INVOICE')
        ->assertFailed();
});

test('invoice request collision without manifest aborts', function () {
    InvoiceRequest::query()->create([
        'invoice_requestable_type' => User::class,
        'invoice_requestable_id' => 999,
        'name' => '[UAT]',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '601',
        'cfdi_use' => 'G03',
        'fiscal_certificate' => AkubicaUatFixtureContract::storagePath('tax/fiscal-certificate.pdf'),
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_INVOICE_REQUEST')
        ->assertFailed();
});

test('cart item collision without manifest aborts', function () {
    $user = User::factory()->create();
    $account = RegularAccount::query()->create([]);
    $customer = Customer::query()->create([
        'user_id' => $user->id,
        'medical_attention_identifier' => AkubicaUatFixtureContract::NAMESPACE.'-customer-primary',
        'customerable_type' => RegularAccount::class,
        'customerable_id' => $account->id,
    ]);
    $category = LaboratoryTestCategory::query()->create(['name' => 'Foreign']);
    $test = LaboratoryTest::query()->create([
        'brand' => 'olab',
        'gda_id' => 'FOREIGN-CART-TEST',
        'name' => 'Foreign',
        'indications' => 'Foreign',
        'public_price_cents' => 100,
        'famedic_price_cents' => 100,
        'laboratory_test_category_id' => $category->id,
    ]);
    LaboratoryCartItem::query()->create(['customer_id' => $customer->id, 'laboratory_test_id' => $test->id]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_CART_ITEM')
        ->assertFailed();
});

test('purchase item collision without manifest aborts', function () {
    $user = User::factory()->create();
    $account = RegularAccount::query()->create([]);
    $customer = Customer::query()->create(['user_id' => $user->id, 'customerable_type' => RegularAccount::class, 'customerable_id' => $account->id]);
    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $customer->id,
        'brand' => 'olab',
        'gda_order_id' => 'FOREIGN-PURCHASE',
        'name' => 'Foreign',
        'paternal_lastname' => 'Foreign',
        'maternal_lastname' => 'Foreign',
        'phone' => '8110000098',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'street' => 'Foreign',
        'number' => '1',
        'neighborhood' => 'Foreign',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100,
    ]);

    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_id' => strtoupper(AkubicaUatFixtureContract::NAMESPACE).'-OLAB',
        'name' => 'Foreign',
        'indications' => 'Foreign',
        'price_cents' => 100,
    ]);

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_COLLISION_PURCHASE_ITEM')
        ->assertFailed();
});

test('queue is explicitly faked and no jobs are pushed', function () {
    Queue::fake();
    Bus::fake();

    eulU01Apply($this);

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
});

test('unexpected throwable is redacted and logged with reference only', function () {
    Log::spy();
    app()->bind(AkubicaUatFixtureMaterializer::class, fn () => new class extends AkubicaUatFixtureMaterializer
    {
        public function assertNoCollisions(AkubicaUatFixturePlan $plan): void
        {
            throw new RuntimeException('secret email akubica-uat-primary@example.invalid phone 8110000001');
        }
    });

    $this->artisan('akubica:uat-fixtures', ['--apply' => true, '--confirm' => AkubicaUatFixtureContract::NAMESPACE])
        ->expectsOutputToContain('UAT_FIXTURE_UNEXPECTED_ERROR')
        ->doesntExpectOutputToContain('akubica-uat-primary@example.invalid')
        ->doesntExpectOutputToContain('8110000001')
        ->assertFailed();

    Log::shouldHaveReceived('error')
        ->with('akubica_uat_fixture_unexpected_error', Mockery::on(fn (array $context): bool => isset($context['error_reference'], $context['exception_class'], $context['phase'], $context['namespace'])
            && $context['exception_class'] === RuntimeException::class
            && ! str_contains(json_encode($context), '8110000001')));
});
