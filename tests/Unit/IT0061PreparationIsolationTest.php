<?php

use App\Console\Commands\MaterializeAkubicaUatR04R05FixturesCommand as R04Command;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class);

beforeEach(function () {
    DB::table('laboratory_appointments')->whereIn('notes', ['it0061-isolation:preexisting', 'akubica-uat-batch2-a4-v1:rc2-r04r05'])->delete();
    DB::table('family_accounts')->where('name', R04Command::FAMILY_NAME.' akubica-uat-batch2-a4-v1')->delete();
    DB::table('laboratory_purchases')->where('street', 'akubica-uat-batch2-a4-v1:rc2-r01-orders')->delete();
    DB::table('akubica_uat_fixture_manifests')->where('namespace', 'akubica-uat-batch2-a4-v1')->delete();
});

test('family-only reset preserves appointments and orders stay namespace and environment safe', function () {
    Http::fake();
    Mail::fake();
    Queue::fake();
    $primary = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    $foreign = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => 'akubica-uat-batch2-a4-v1',
        'fixture_version' => 1,
        'status' => 'active',
        'expires_at' => now()->addDay(),
        'metadata' => ['ids' => ['customers' => ['primary' => $primary->id, 'foreign' => $foreign->id], 'store' => []]],
    ]);

    $appointment = LaboratoryAppointment::factory()->withNotes('it0061-isolation:preexisting')->create(['customer_id' => $primary->id]);
    $this->artisan('akubica:uat-fixtures-r04r05', [
        '--apply' => true, '--family-only' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1',
    ])->assertSuccessful();
    $this->artisan('akubica:uat-fixtures-r04r05', [
        '--reset' => true, '--family-only' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1',
    ])->assertSuccessful();

    expect(LaboratoryAppointment::query()->whereKey($appointment->id)->exists())->toBeTrue()
        ->and(FamilyAccount::query()->whereIn('customer_id', [$primary->id, $foreign->id])->where('name', R04Command::FAMILY_NAME.' akubica-uat-batch2-a4-v1')->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('orders reject production and unknown environment without writes', function () {
    $this->app->detectEnvironment(fn () => 'production');
    $this->artisan('akubica:uat-fixtures-orders', ['--apply' => true, '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1'])->assertFailed();
    $this->app->detectEnvironment(fn () => 'qa');
    $this->artisan('akubica:uat-fixtures-orders', ['--apply' => true, '--namespace' => 'akubica-uat-batch2-a4-v1', '--confirm' => 'akubica-uat-batch2-a4-v1'])->assertFailed();
    expect(LaboratoryPurchase::query()->where('street', 'it0061-isolation:orders')->count())->toBe(0);
});
