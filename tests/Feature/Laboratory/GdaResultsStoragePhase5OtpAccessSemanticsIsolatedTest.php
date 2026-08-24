<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\LaboratoryResultsController;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Laboratory\LabResultsAccessTokenService;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase5OtpAccessSemanticsIsolatedTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        NotificationFacade::fake();
        config([
            'laboratory-results.otp_required' => true,
            'laboratory-results.public_session_minutes' => 15,
            'otp.expiry' => 10,
        ]);

        $this->bootstrapIsolatedSchema();
        $this->bootstrapOtpAccessSchema();
        $this->withoutOtpFlowMiddleware();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('otp_access_logs');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('lab_result_access_tokens');
        Schema::enableForeignKeyConstraints();

        $this->tearDownIsolatedSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function test_1_solicitar_otp_no_registra_read_at(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();

        $this->post(route('lab-results.send-otp'), [
            'token' => $token,
            'channel' => 'email',
        ])->assertOk();

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_2_otp_validado_sin_abrir_pdf_no_registra_read_at(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issuePendingOtp($user, $purchase, '123456');

        $this->post(route('lab-results.verify'), [
            'token' => $token,
            'code' => '123456',
        ])->assertOk();

        $this->assertTrue(
            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('laboratory_purchase_id', $purchase->id)
                ->where('status', OtpCode::STATUS_VERIFIED)
                ->exists()
        );
        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_3_otp_pdf_gda_current_abierto_registra_acceso(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issueVerifiedOtp($user, $purchase);

        $this->get($this->signedPdfUrl($token, 'inline'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_4_otp_download_pdf_current_registra_acceso(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issueVerifiedOtp($user, $purchase);

        $this->get($this->signedPdfUrl($token, 'attachment'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="resultados-laboratorio.pdf"');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_5_otp_pdf_stale_sirve_anterior_y_no_marca_notif_nueva(): void
    {
        Bus::fake();

        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase, $token] = $this->seedOtpReadyOrder();
        $pathA = $purchase->results;

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);
        $this->issueVerifiedOtp($user, $purchase);

        $response = $this->get($this->signedPdfUrl($token, 'inline'));

        $response->assertOk();
        $this->assertSame($this->samplePdfBinary('A'), $response->getContent());
        $this->assertSame($pathA, $purchase->fresh()->results);
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function test_6_despues_del_refresh_sin_reabrir_sigue_new(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase, $token] = $this->seedOtpReadyOrder();
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);
        $this->issueVerifiedOtp($user, $purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $this->assertNull($notifB->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertNotEmpty($token);
        $this->assertNotEmpty($user->email);
    }

    #[Test]
    public function test_7_volver_a_abrir_pdf_actualizado_via_otp_deja_de_ser_new(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase, $token] = $this->seedOtpReadyOrder();
        $this->seedResultsNotificationRecord($purchase, [
            'read_at' => Carbon::parse('2026-08-21 17:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $notifB = $this->seedResultsNotificationRecord($purchase);
        $this->issueVerifiedOtp($user, $purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notifB->id);
        touch(Storage::path($pathB), now()->addMinute()->timestamp);

        $this->get($this->signedPdfUrl($token, 'inline'))
            ->assertOk()
            ->assertSee($this->samplePdfBinary('B'), false);

        $this->assertNotNull($notifB->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame($pathB, $purchase->fresh()->results);
    }

    #[Test]
    public function test_8_pdf_manual_via_otp_es_consistente_con_fase_4(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder([
            'results' => 'results/manual-admin.pdf',
        ], storeGdaPdf: false);
        Storage::put('results/manual-admin.pdf', $this->samplePdfBinary('manual'));
        touch(Storage::path('results/manual-admin.pdf'), now()->timestamp);
        $purchase->update(['results' => 'results/manual-admin.pdf']);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $later = $this->seedResultsNotificationRecord($purchase);
        $this->issueVerifiedOtp($user, $purchase);

        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());

        $this->get($this->signedPdfUrl($token, 'inline'))
            ->assertOk()
            ->assertSee($this->samplePdfBinary('manual'), false);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($later->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);
    }

    #[Test]
    public function test_9_otp_invalido_no_registra_read_at(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issuePendingOtp($user, $purchase, '123456');

        $this->post(route('lab-results.verify'), [
            'token' => $token,
            'code' => '000000',
        ])->assertRedirect(route('lab-results.show', ['token' => $token]));

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_10_otp_expirado_no_registra_read_at(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issuePendingOtp($user, $purchase, '123456', expiresAt: now()->subMinute());

        $this->post(route('lab-results.verify'), [
            'token' => $token,
            'code' => '123456',
        ])->assertRedirect(route('lab-results.show', ['token' => $token]));

        $this->assertSame(OtpCode::STATUS_EXPIRED, OtpCode::query()->latest('id')->first()->status);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_11_archivo_inexistente_no_registra_read_at(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder([
            'results' => 'results/gda-missing.pdf',
        ], storeGdaPdf: false);
        $this->issueVerifiedOtp($user, $purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andThrow(new \RuntimeException('gda down'));
        });

        $this->get($this->signedPdfUrl($token, 'inline'))->assertStatus(503);

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_12_fetch_tecnico_dentro_del_flujo_otp_no_registra_read_at(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issuePendingOtp($user, $purchase, '123456');

        $this->post(route('lab-results.verify'), [
            'token' => $token,
            'code' => '123456',
        ])->assertOk();

        $this->get(route('lab-results.show', ['token' => $token]))->assertOk();

        $fetch = app(LaboratoryResultsController::class)->fetch(
            Request::create('/fake', 'POST'),
            $purchase->id
        );

        $this->assertTrue($fetch->getData(true)['success']);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    #[Test]
    public function test_13_pending_results_count_antes_y_despues_de_otp_current(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();
        $this->issueVerifiedOtp($user, $purchase);

        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(1, $user->fresh()->pending_results_count);

        $this->get($this->signedPdfUrl($token, 'inline'))->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertFalse($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(0, $user->fresh()->pending_results_count);
    }

    #[Test]
    public function test_14_enlace_compartido_no_consume_read_at_del_paciente(): void
    {
        [$user, $purchase, $token, $notification] = $this->seedOtpReadyOrder();

        $sharedPdf = URL::temporarySignedRoute(
            'lab-results.shared-pdf',
            now()->addHours(12),
            ['token' => $token]
        );

        $this->get($sharedPdf)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
        $this->assertSame(1, $user->fresh()->pending_results_count);

        $sharedPage = URL::temporarySignedRoute(
            'lab-results.show-shared',
            now()->addHours(12),
            ['token' => $token, 'sharedByName' => $user->name]
        );

        $this->get($sharedPage)->assertOk();
        $this->assertNull($notification->fresh()->read_at);
        $this->assertTrue($purchase->fresh()->hasUnseenResultsForPatient());
    }

    private function withoutOtpFlowMiddleware(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EnsurePhoneIsVerified::class,
            \App\Http\Middleware\EnsureUserHasCustomerAccount::class,
        ]);
    }

    private function bootstrapOtpAccessSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('otp_access_logs');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('lab_result_access_tokens');
        Schema::enableForeignKeyConstraints();

        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone')->nullable();
                $table->string('phone_country')->nullable();
            });
        }

        Schema::create('lab_result_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_purchase_id')->constrained('laboratory_purchases')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32)->default('lab_results');
            $table->uuid('challenge_id')->nullable();
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained('laboratory_purchases')->cascadeOnDelete();
            $table->string('channel', 16)->default('email');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->string('event', 64);
            $table->string('channel', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase, 2: string, 3: LaboratoryNotification}
     */
    private function seedOtpReadyOrder(array $purchaseOverrides = [], bool $storeGdaPdf = true): array
    {
        $purchase = $this->seedPurchase($purchaseOverrides);
        $user = $purchase->customer->user;
        $user->forceFill([
            'phone' => '5555555555',
            'phone_country' => 'MX',
        ])->save();

        if ($storeGdaPdf) {
            $this->storeGdaPdf($purchase, 'A');
        }

        $receivedAt = $storeGdaPdf ? now()->subHour() : now();
        $notification = $this->seedResultsNotificationRecord($purchase, [
            'results_received_at' => $receivedAt,
        ]);

        $token = app(LabResultsAccessTokenService::class)->generate($user->fresh(), $purchase);

        return [$user->fresh(), $purchase->fresh(), $token, $notification];
    }

    private function issuePendingOtp(User $user, LaboratoryPurchase $purchase, string $plainCode, ?Carbon $expiresAt = null): OtpCode
    {
        return OtpCode::query()->create([
            'user_id' => $user->id,
            'laboratory_purchase_id' => $purchase->id,
            'channel' => OtpCode::CHANNEL_EMAIL,
            'code' => Hash::make($plainCode),
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
            'attempts' => 0,
            'status' => OtpCode::STATUS_PENDING,
        ]);
    }

    private function issueVerifiedOtp(User $user, LaboratoryPurchase $purchase): OtpCode
    {
        return OtpCode::query()->create([
            'user_id' => $user->id,
            'laboratory_purchase_id' => $purchase->id,
            'channel' => OtpCode::CHANNEL_EMAIL,
            'code' => Hash::make('123456'),
            'expires_at' => now(),
            'attempts' => 0,
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);
    }

    private function signedPdfUrl(string $token, string $disposition = 'inline'): string
    {
        return URL::temporarySignedRoute('lab-results.pdf', now()->addMinutes(15), [
            'token' => $token,
            'disposition' => $disposition,
        ]);
    }

    private function storeGdaPdf(LaboratoryPurchase $purchase, string $marker): string
    {
        $binary = $this->samplePdfBinary($marker);
        $path = sprintf(
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $purchase->id,
            substr(hash('sha256', $binary), 0, 12)
        );
        Storage::put($path, $binary);
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);

        return $path;
    }

    private function seedResultsNotificationRecord(LaboratoryPurchase $purchase, array $overrides = []): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create(array_merge([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'resource_type' => 'ServiceRequest',
            'results_received_at' => now(),
            'payload' => [
                'header' => ['marca' => 5],
                'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
                'id' => $purchase->gda_order_id,
            ],
        ], $overrides));
    }

    private function samplePdfBinary(string $marker = 'A'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Marker ({$marker}) >>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function samplePdfBase64(string $marker = 'A'): string
    {
        return base64_encode($this->samplePdfBinary($marker));
    }

    private function seedPurchase(array $overrides = []): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente OTP',
            'email' => 'patient-otp-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'GDA-OTP-'.uniqid(),
            'gda_consecutivo' => 2309,
            'name' => 'Juan',
            'paternal_lastname' => 'Perez',
            'maternal_lastname' => 'Lopez',
            'phone' => '5555555555',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'CDMX',
            'city' => 'CDMX',
            'zipcode' => '01000',
            'total_cents' => 10000,
            'status' => 'pending',
        ], $overrides));
    }
}
