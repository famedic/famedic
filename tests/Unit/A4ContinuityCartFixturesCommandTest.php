<?php

namespace Tests\Unit;

use App\Console\Commands\A4ContinuityCartFixturesCommand;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;
use Tests\TestCase;

final class A4ContinuityCartFixturesCommandTest extends TestCase
{
    // INT-031 (RC05): RefreshDatabase migrates the schema when this Unit class runs on its own, then wraps each
    // test in the same transaction DatabaseTransactions used.
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function manifest(): array
    {
        $user = User::factory()->withRegularCustomer()->create();
        $manifest = AkubicaUatFixtureManifest::query()->create([
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => ['ids' => ['customers' => ['membership_none' => $user->customer->id]]],
        ]);

        return [$manifest, $user];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('akubica_uat.allowed_environments', ['testing']);
        config()->set('akubica_connectors.continuity.laboratory.enabled', true);
        config()->set('akubica_connectors.continuity.laboratory.certified', true);
        config()->set('akubica_connectors.continuity.allowed_host', 'famedic-a4.test');
        URL::forceRootUrl('https://famedic-a4.test');
        URL::forceScheme('https');
        SyntheticConsumerRegistryFixture::reset();
        Storage::disk('local')->delete(AkubicaUatFixtureContract::storagePath('a4-continuity-cart.json', AkubicaUatFixtureContract::DEDICATED_NAMESPACE));
    }

    public function test_dry_run_apply_is_idempotent_and_cleanup_is_scoped(): void
    {
        [$manifest, $user] = $this->manifest();
        $args = ['--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE];

        $this->artisan('akubica:a4-continuity-cart', $args)->assertSuccessful();
        self::assertSame(0, LaboratoryCartItem::query()->count());

        $this->artisan('akubica:a4-continuity-cart', $args + ['--apply' => true, '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM])->assertSuccessful();
        $item = LaboratoryCartItem::query()->with('laboratoryTest')->sole();
        self::assertSame($user->customer->id, $item->customer_id);
        self::assertSame('olab', $item->laboratoryTest->brand->value);

        $this->artisan('akubica:a4-continuity-cart', $args + ['--apply' => true, '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM])->assertSuccessful();
        self::assertSame(1, LaboratoryCartItem::query()->count());

        $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:laboratory:read'], ['olab']);
        $response = $this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', [
            'Authorization' => 'Bearer '.$leo['token']->plainTextToken,
            'X-Synthetic-Machine-Credential' => $leo['machine']->plainTextToken,
        ])->assertOk();
        self::assertStringStartsWith('https://famedic-a4.test/', (string) $response->json('data.continuity_url'));
        self::assertNotNull($response->json('data.continuity_expires_at'));
        self::assertFalse((bool) $response->json('data.assistant_can_pay'));
        self::assertFalse((bool) $response->json('data.can_continue_to_payment_platform'));

        $this->artisan('akubica:a4-continuity-cart', $args + ['--cleanup' => true, '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM])->assertSuccessful();
        self::assertSame(0, LaboratoryCartItem::query()->count());
        self::assertSame(0, LaboratoryTest::query()->where('gda_id', 'like', A4ContinuityCartFixturesCommand::TEST_PREFIX.'%')->count());
        self::assertSame(0, LaboratoryTestCategory::query()->where('name', '[UAT] RC2 A4 continuidad laboratorio · '.AkubicaUatFixtureContract::DEDICATED_NAMESPACE)->count());
        $manifest->delete();
    }

    public function test_preexisting_category_is_preserved_after_a4_cleanup(): void
    {
        [$manifest] = $this->manifest();
        LaboratoryTestCategory::query()->create(['name' => '[UAT] RC2 A4 continuidad laboratorio · '.AkubicaUatFixtureContract::DEDICATED_NAMESPACE]);
        $args = [
            '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            '--apply' => true,
            '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
        ];
        $this->artisan('akubica:a4-continuity-cart', $args)->assertSuccessful();
        $this->artisan('akubica:a4-continuity-cart', [
            '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            '--cleanup' => true,
            '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
        ])->assertSuccessful();

        self::assertSame(1, LaboratoryTestCategory::query()->where('name', '[UAT] RC2 A4 continuidad laboratorio · '.AkubicaUatFixtureContract::DEDICATED_NAMESPACE)->count());
        $manifest->delete();
    }

    public function test_cleanup_recovers_when_item_is_missing_and_removes_only_owned_category(): void
    {
        [$manifest] = $this->manifest();
        $args = [
            '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            '--apply' => true,
            '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
        ];
        $this->artisan('akubica:a4-continuity-cart', $args)->assertSuccessful();
        $testId = LaboratoryTest::query()->where('gda_id', A4ContinuityCartFixturesCommand::TEST_PREFIX.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-OLAB')->value('id');
        LaboratoryCartItem::query()->where('laboratory_test_id', $testId)->forceDelete();

        $this->artisan('akubica:a4-continuity-cart', [
            '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            '--cleanup' => true,
            '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
        ])->assertSuccessful();

        self::assertSame(0, LaboratoryTest::query()->where('gda_id', 'like', A4ContinuityCartFixturesCommand::TEST_PREFIX.'%')->count());
        self::assertSame(0, LaboratoryTestCategory::query()->where('name', '[UAT] RC2 A4 continuidad laboratorio · '.AkubicaUatFixtureContract::DEDICATED_NAMESPACE)->count());
        $manifest->delete();
    }

    public function test_existing_foreign_item_is_rejected_without_modification(): void
    {
        [$manifest] = $this->manifest();
        $gda = A4ContinuityCartFixturesCommand::TEST_PREFIX.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-OLAB';
        $test = LaboratoryTest::factory()->create(['brand' => 'olab', 'gda_id' => $gda]);
        $foreign = User::factory()->withRegularCustomer()->create();
        $item = LaboratoryCartItem::factory()->create(['customer_id' => $foreign->customer->id, 'laboratory_test_id' => $test->id]);

        $this->artisan('akubica:a4-continuity-cart', [
            '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            '--apply' => true,
            '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
        ])->assertFailed();

        self::assertDatabaseHas('laboratory_cart_items', ['id' => $item->id, 'customer_id' => $foreign->customer->id]);
        $manifest->delete();
    }
}
