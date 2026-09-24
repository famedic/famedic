<?php

namespace Tests\Feature\InvoiceRequests;

use App\Actions\CreateInvoiceRequestAction;
use App\Actions\InvoiceRequests\ActivateLaboratoryInvoiceRequestAction;
use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestStatusLog;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceRequestWorkflowPhase1Test extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-invoice-workflow-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'app.env' => 'testing',
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
            'taxregimes.uses' => [
                'G03' => 'Gastos en general.',
            ],
        ]);
        Storage::forgetDisk('local');

        Notification::fake();

        $this->bootstrapSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        if (! empty($this->storageRoot) && is_dir($this->storageRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function nueva_solicitud_laboratorio_inicia_en_awaiting_sample_collection(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makeLaboratoryPurchase($customer);

        $request = app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');

        $this->assertSame(
            InvoiceRequestWorkflowStatus::AwaitingSampleCollection,
            $request->fresh()->workflow_status
        );
        $this->assertNull($request->submitted_to_billing_at);
        $this->assertNull($request->activated_by);

        $createdLog = InvoiceRequestStatusLog::query()->sole();
        $this->assertNull($createdLog->from_status);
        $this->assertSame(InvoiceRequestWorkflowStatus::AwaitingSampleCollection, $createdLog->to_status);
        $this->assertSame(InvoiceRequestStatusLogTrigger::Created, $createdLog->trigger);
    }

    #[Test]
    public function activacion_transiciona_a_submitted_to_billing(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();

        app(ActivateLaboratoryInvoiceRequestAction::class)->execute(
            $request,
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );

        $request->refresh();
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
    }

    #[Test]
    public function activacion_persiste_submitted_to_billing_at(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();

        $this->travelTo(now()->startOfSecond());

        app(ActivateLaboratoryInvoiceRequestAction::class)->execute(
            $request,
            'sample_webhook',
        );

        $request->refresh();
        $this->assertNotNull($request->submitted_to_billing_at);
        $this->assertTrue($request->submitted_to_billing_at->equalTo(now()));
    }

    #[Test]
    public function activacion_persiste_activated_by(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();

        app(ActivateLaboratoryInvoiceRequestAction::class)->execute($request, 'sample_webhook');
        $this->assertSame('sample_webhook', $request->fresh()->activated_by);

        $request = $this->makeAwaitingInvoiceRequest();
        app(ActivateLaboratoryInvoiceRequestAction::class)->execute($request, 'result_available');
        $this->assertSame('result_available', $request->fresh()->activated_by);
    }

    #[Test]
    public function activacion_es_idempotente(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();
        $action = app(ActivateLaboratoryInvoiceRequestAction::class);

        $first = $action->execute($request, 'sample_webhook');
        $second = $action->execute($first, 'sample_webhook');

        $this->assertSame($first->submitted_to_billing_at?->toIso8601String(), $second->submitted_to_billing_at?->toIso8601String());
        $this->assertSame('sample_webhook', $second->activated_by);

        $transitionLogs = InvoiceRequestStatusLog::query()
            ->where('invoice_request_id', $request->id)
            ->where('trigger', InvoiceRequestStatusLogTrigger::SampleWebhook->value)
            ->where('to_status', InvoiceRequestWorkflowStatus::SubmittedToBilling->value)
            ->get();

        $this->assertCount(1, $transitionLogs);
        $this->assertCount(2, InvoiceRequestStatusLog::query()->where('invoice_request_id', $request->id)->get());
    }

    #[Test]
    public function activacion_registra_auditoria_de_transicion(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();

        app(ActivateLaboratoryInvoiceRequestAction::class)->execute(
            $request,
            InvoiceRequestStatusLogTrigger::AdminManual,
        );

        $transitionLog = InvoiceRequestStatusLog::query()
            ->where('invoice_request_id', $request->id)
            ->where('trigger', InvoiceRequestStatusLogTrigger::AdminManual->value)
            ->sole();

        $this->assertSame(InvoiceRequestWorkflowStatus::AwaitingSampleCollection, $transitionLog->from_status);
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $transitionLog->to_status);
        $this->assertSame(InvoiceRequestStatusLogTrigger::AdminManual, $transitionLog->trigger);
        $this->assertNull($transitionLog->metadata);
    }

    #[Test]
    public function solicitud_cancelada_no_se_activa(): void
    {
        $request = $this->makeAwaitingInvoiceRequest();
        $request->update([
            'workflow_status' => InvoiceRequestWorkflowStatus::Cancelled->value,
        ]);

        $result = app(ActivateLaboratoryInvoiceRequestAction::class)->execute(
            $request->fresh(),
            'sample_webhook',
        );

        $this->assertSame(InvoiceRequestWorkflowStatus::Cancelled, $result->workflow_status);
        $this->assertNull($result->submitted_to_billing_at);
        $this->assertNull($result->activated_by);

        $this->assertCount(1, InvoiceRequestStatusLog::query()->where('invoice_request_id', $request->id)->get());
    }

    private function makeAwaitingInvoiceRequest(): InvoiceRequest
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makeLaboratoryPurchase($customer);

        return app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
    }

    /**
     * @return array{0: User, 1: TaxProfile, 2: Customer}
     */
    private function makeCustomerWithProfile(): array
    {
        $user = User::query()->create([
            'name' => 'Paciente',
            'email' => 'owner-'.uniqid('', true).'@test.local',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'customerable_type' => 'App\\Models\\RegularAccount',
            'customerable_id' => 1,
        ]);

        $profile = $customer->taxProfiles()->create([
            'name' => 'Persona Fiscal',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/test-'.$user->id.'.pdf',
        ]);

        return [$user, $profile, $customer];
    }

    private function makeLaboratoryPurchase(Customer $customer): LaboratoryPurchase
    {
        return LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'gda_order_id' => (string) random_int(100000, 999999),
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 10000,
        ]);
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoice_request_status_logs',
            'invoice_requests',
            'laboratory_purchases',
            'tax_profiles',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('customerable_type')->nullable();
            $table->unsignedBigInteger('customerable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('name');
            $table->string('rfc')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('cfdi_use')->nullable();
            $table->string('fiscal_certificate')->nullable();
            $table->string('razon_social')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
            $table->string('gda_status')->default('pending');
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone');
            $table->string('phone_country')->default('MX');
            $table->date('birth_date');
            $table->string('gender')->nullable();
            $table->string('street');
            $table->string('number');
            $table->string('neighborhood');
            $table->string('state');
            $table->string('city');
            $table->string('zipcode');
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_profile_id')->nullable()->constrained('tax_profiles')->nullOnDelete();
            $table->morphs('invoice_requestable', 'invoice_requestable_index');
            $table->string('name');
            $table->string('rfc');
            $table->string('zipcode');
            $table->string('tax_regime');
            $table->string('cfdi_use');
            $table->string('fiscal_certificate');
            $table->string('workflow_status', 50)
                ->default(InvoiceRequestWorkflowStatus::AwaitingSampleCollection->value);
            $table->timestamp('submitted_to_billing_at')->nullable();
            $table->timestamp('sample_completed_at')->nullable();
            $table->timestamp('billing_team_notified_at')->nullable();
            $table->string('activated_by', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_request_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_request_id')->constrained('invoice_requests')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->string('trigger', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoice_request_status_logs',
            'invoice_requests',
            'laboratory_purchases',
            'tax_profiles',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
