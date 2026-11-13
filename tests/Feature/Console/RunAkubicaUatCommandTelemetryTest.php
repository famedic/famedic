<?php

namespace Tests\Feature\Console;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RunAkubicaUatCommandTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('akubica_uat.identities', array_combine(
            ['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'],
            array_map(static fn (int $n): array => ['email' => "uat{$n}@synthetic.invalid", 'phone' => "555000000{$n}", 'country' => 'MX'], range(1, 7)),
        ));
        putenv('APP_COMMIT_SHA=telemetry-test-sha');

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('customers', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('akubica_uat_fixture_manifests', static function (Blueprint $table): void {
            $table->id();
            $table->string('namespace');
            $table->unsignedInteger('fixture_version');
            $table->string('status');
            $table->json('metadata');
            $table->timestamps();
        });

        foreach (range(1, 3) as $id) {
            \DB::table('users')->insert(['id' => $id]);
            \DB::table('customers')->insert(['id' => $id, 'user_id' => $id]);
        }
        AkubicaUatFixtureManifest::create([
            'namespace' => AkubicaUatFixtureContract::NAMESPACE,
            'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'metadata' => ['ids' => ['users' => [
                'membership_none' => 1,
                'membership_regular' => 2,
                'membership_institutional' => 3,
            ]]],
        ]);
    }

    public function test_dry_run_reports_success_without_writes(): void
    {
        $exit = Artisan::call('akubica:uat', [
            '--dry-run' => true,
            '--expected-sha' => 'telemetry-test-sha',
            '--scenarios' => 'B08,B09,B10',
        ]);

        $output = Artisan::output();
        $this->assertSame(Command::SUCCESS, $exit, $output);
        $this->assertStringContainsString('"status":"ok"', $output);
        $this->assertStringContainsString('"scenarios":["B08","B09","B10"]', $output);
        $this->assertStringNotContainsString('55500000', $output);
    }

    public function test_missing_commit_reports_sanitized_controlled_failure(): void
    {
        putenv('APP_COMMIT_SHA=');
        $exit = Artisan::call('akubica:uat', [
            '--dry-run' => true,
            '--expected-sha' => 'telemetry-test-sha',
            '--scenarios' => 'B08,B09,B10',
        ]);

        $output = Artisan::output();
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('"stage":"assertSha"', $output);
        $this->assertStringContainsString('"exception_type":"DomainException"', $output);
        $this->assertStringContainsString('"error_code":"UAT_ASSERTSHA_FAILED"', $output);
        $this->assertStringNotContainsString('telemetry-test-sha', $output);
    }
}
