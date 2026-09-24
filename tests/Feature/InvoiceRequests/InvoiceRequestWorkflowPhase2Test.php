<?php

namespace Tests\Feature\InvoiceRequests;

use App\Actions\CreateInvoiceRequestAction;
use App\Actions\InvoiceRequests\ActivateLaboratoryInvoiceRequestAction;
use App\Actions\InvoiceRequests\AttemptActivateLaboratoryInvoiceRequestForPurchaseAction;
use App\Actions\Laboratory\HandleSampleCollectionNotificationAction;
use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestStatusLog;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\TaxProfile;
use App\Models\User;
use App\Notifications\LaboratoryPurchaseInvoiceRequested;
use App\Services\Laboratory\LabOrderNotificationGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceRequestWorkflowPhase2Test extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-invoice-workflow-p2-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'app.env' => 'testing',
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
            'taxregimes.uses' => [
                'G03' => 'Gastos en general.',
            ],
            'services.laboratory_invoice_request.skip_admin_mail' => false,
            'services.laboratory_invoice_request.test_notify_email' => 'billing@test.local',
            'services.laboratory_invoice_request.allow_fallback_to_invoice_admins' => false,
        ]);
        Storage::forgetDisk('local');
        Notification::fake();

        $this->bootstrapSchema();
        $this->makeBillingUser();
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
    public function paciente_solicita_factura_sin_correo_ni_notificacion(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $request = $this->createInvoiceRequest($purchase);

        $this->assertSame(InvoiceRequestWorkflowStatus::AwaitingSampleCollection, $request->workflow_status);
        $this->assertNull($request->billing_team_notified_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function toma_completa_despues_de_solicitud_activa_y_notifica(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $request = $this->createInvoiceRequest($purchase);

        $this->registerSampleEvents($purchase, ['A', 'B']);

        $request->refresh();
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
        $this->assertSame('sample_webhook', $request->activated_by);
        $this->assertNotNull($request->sample_completed_at);
        $this->assertNotNull($request->submitted_to_billing_at);
        $this->assertNotNull($request->billing_team_notified_at);

        Notification::assertSentTo(
            User::query()->where('email', 'billing@test.local')->first(),
            LaboratoryPurchaseInvoiceRequested::class
        );
        Notification::assertCount(1);
    }

    #[Test]
    public function resultado_disponible_como_fallback_activa_sin_sample_completed_at(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $request = $this->createInvoiceRequest($purchase);

        $this->registerResultEvents($purchase, ['A', 'B']);

        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::ResultAvailable,
        );

        $request->refresh();
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
        $this->assertSame('result_available', $request->activated_by);
        $this->assertNull($request->sample_completed_at);
        $this->assertNotNull($request->submitted_to_billing_at);
        $this->assertNotNull($request->billing_team_notified_at);
        Notification::assertCount(1);
    }

    #[Test]
    public function toma_previa_activa_inmediatamente_al_solicitar_factura(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $this->registerSampleEvents($purchase, ['A', 'B']);

        $request = $this->createInvoiceRequest($purchase);

        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
        $this->assertSame('sample_webhook', $request->activated_by);
        $this->assertNotNull($request->sample_completed_at);
        Notification::assertCount(1);
    }

    #[Test]
    public function resultado_previo_activa_inmediatamente_al_solicitar_factura(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $this->registerResultEvents($purchase, ['A']);

        $request = $this->createInvoiceRequest($purchase);

        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
        $this->assertSame('result_available', $request->activated_by);
        $this->assertNull($request->sample_completed_at);
        Notification::assertCount(1);
    }

    #[Test]
    public function muestra_parcial_no_activa(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $request = $this->createInvoiceRequest($purchase);

        $this->registerSampleEvents($purchase, ['A']);

        $request->refresh();
        $this->assertSame(InvoiceRequestWorkflowStatus::AwaitingSampleCollection, $request->workflow_status);
        Notification::assertNothingSent();
    }

    #[Test]
    public function todas_las_muestras_completas_activa_una_sola_vez(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $this->createInvoiceRequest($purchase);

        $this->registerSampleEvents($purchase, ['A']);
        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );

        $this->registerSampleEvents($purchase, ['B']);
        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );

        $request = $purchase->fresh()->invoiceRequest;
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);

        $transitionLogs = InvoiceRequestStatusLog::query()
            ->where('invoice_request_id', $request->id)
            ->where('to_status', InvoiceRequestWorkflowStatus::SubmittedToBilling->value)
            ->get();

        $this->assertCount(1, $transitionLogs);
        Notification::assertCount(1);
    }

    #[Test]
    public function resultado_parcial_no_activa(): void
    {
        $purchase = $this->makePurchaseWithItems(3);
        $this->createInvoiceRequest($purchase);

        $this->registerResultEvents($purchase, ['A', 'B']);

        $request = $purchase->fresh()->invoiceRequest;
        $this->assertSame(InvoiceRequestWorkflowStatus::AwaitingSampleCollection, $request->workflow_status);
        Notification::assertNothingSent();
    }

    #[Test]
    public function webhook_duplicado_no_duplica_activacion_ni_correo(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $this->createInvoiceRequest($purchase);
        $this->registerSampleEvents($purchase, ['A']);

        $action = app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class);
        $action->execute($purchase->fresh(['invoiceRequest']), InvoiceRequestStatusLogTrigger::SampleWebhook);
        $action->execute($purchase->fresh(['invoiceRequest']), InvoiceRequestStatusLogTrigger::SampleWebhook);

        $this->assertCount(1, InvoiceRequestStatusLog::query()
            ->where('to_status', InvoiceRequestWorkflowStatus::SubmittedToBilling->value)
            ->get());
        Notification::assertCount(1);
    }

    #[Test]
    public function resultado_duplicado_no_duplica_activacion_ni_correo(): void
    {
        $purchase = $this->makePurchaseWithItems(2);
        $this->createInvoiceRequest($purchase);
        $this->registerResultEvents($purchase, ['A', 'B']);

        $action = app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class);
        $action->execute($purchase->fresh(['invoiceRequest']), InvoiceRequestStatusLogTrigger::ResultAvailable);
        $action->execute($purchase->fresh(['invoiceRequest']), InvoiceRequestStatusLogTrigger::ResultAvailable);

        Notification::assertCount(1);
        $this->assertCount(1, InvoiceRequestStatusLog::query()
            ->where('to_status', InvoiceRequestWorkflowStatus::SubmittedToBilling->value)
            ->get());
    }

    #[Test]
    public function toma_y_resultado_concurrentes_solo_activan_una_vez(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $this->createInvoiceRequest($purchase);
        $this->registerSampleEvents($purchase, ['A']);
        $this->registerResultEvents($purchase, ['A']);

        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );
        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::ResultAvailable,
        );

        $request = $purchase->fresh()->invoiceRequest;
        $this->assertSame('sample_webhook', $request->activated_by);
        $this->assertCount(1, InvoiceRequestStatusLog::query()
            ->where('to_status', InvoiceRequestWorkflowStatus::SubmittedToBilling->value)
            ->get());
        Notification::assertCount(1);
    }

    #[Test]
    public function solicitud_cancelada_no_se_activa_por_toma_ni_resultado(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $request = $this->createInvoiceRequest($purchase);
        $request->update(['workflow_status' => InvoiceRequestWorkflowStatus::Cancelled->value]);

        $this->registerSampleEvents($purchase, ['A']);
        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );

        $this->registerResultEvents($purchase, ['A']);
        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest']),
            InvoiceRequestStatusLogTrigger::ResultAvailable,
        );

        $request->refresh();
        $this->assertSame(InvoiceRequestWorkflowStatus::Cancelled, $request->workflow_status);
        Notification::assertNothingSent();
    }

    #[Test]
    public function handle_sample_collection_notification_activa_solicitud_al_completar_toma(): void
    {
        $purchase = $this->makePurchaseWithItems(1);
        $this->createInvoiceRequest($purchase);

        $notification = LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_order_id' => $purchase->gda_order_id,
            'notification_type' => LaboratoryNotification::TYPE_SAMPLE_COLLECTION,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'payload' => [],
        ]);

        $payload = $this->gatePayload('A', 'sample-A');
        $payload['id'] = $purchase->gda_order_id;

        app(HandleSampleCollectionNotificationAction::class)->execute(
            $notification,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => ['order_id' => $purchase->gda_order_id],
            ],
        );

        $request = $purchase->fresh()->invoiceRequest;
        $this->assertSame(InvoiceRequestWorkflowStatus::SubmittedToBilling, $request->workflow_status);
        $this->assertSame('sample_webhook', $request->activated_by);
        Notification::assertSentTo(
            User::query()->where('email', 'billing@test.local')->first(),
            LaboratoryPurchaseInvoiceRequested::class
        );
        Notification::assertSentTimes(LaboratoryPurchaseInvoiceRequested::class, 1);
    }

    private function makeBillingUser(): User
    {
        return User::query()->create([
            'name' => 'Billing',
            'email' => 'billing@test.local',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function createInvoiceRequest(LaboratoryPurchase $purchase): InvoiceRequest
    {
        [, $profile] = $this->makeCustomerWithProfile($purchase->customer_id);
        Storage::put($profile->fiscal_certificate, '%PDF');

        return app(CreateInvoiceRequestAction::class)($purchase->fresh(), $profile, 'G03');
    }

    private function makePurchaseWithItems(int $items): LaboratoryPurchase
    {
        [, , $customer] = $this->makeCustomerWithProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);

        for ($i = 0; $i < $items; $i++) {
            LaboratoryPurchaseItem::query()->create([
                'laboratory_purchase_id' => $purchase->id,
                'name' => 'Estudio '.chr(65 + $i),
                'gda_id' => 1000 + $i,
                'indications' => 'Indicaciones',
                'price_cents' => 10000,
            ]);
        }

        return $purchase->fresh(['laboratoryPurchaseItems']);
    }

    /**
     * @param  list<string>  $studies
     */
    private function registerSampleEvents(LaboratoryPurchase $purchase, array $studies): void
    {
        $gate = app(LabOrderNotificationGateService::class);

        foreach ($studies as $study) {
            $gate->registerEvent(
                gdaOrderId: $purchase->gda_order_id,
                eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
                purchase: $purchase->fresh(['laboratoryPurchaseItems']),
                studyExternalId: $study,
                providerEventId: 'sample-'.$study,
                payload: $this->gatePayload($study, 'sample-'.$study),
            );
        }

        app(AttemptActivateLaboratoryInvoiceRequestForPurchaseAction::class)->execute(
            $purchase->fresh(['invoiceRequest', 'laboratoryPurchaseItems']),
            InvoiceRequestStatusLogTrigger::SampleWebhook,
        );
    }

    /**
     * @param  list<string>  $studies
     */
    private function registerResultEvents(LaboratoryPurchase $purchase, array $studies): void
    {
        $gate = app(LabOrderNotificationGateService::class);

        foreach ($studies as $study) {
            $gate->registerEvent(
                gdaOrderId: $purchase->gda_order_id,
                eventType: LabOrderNotificationGateService::EVENT_RESULTS,
                purchase: $purchase->fresh(['laboratoryPurchaseItems']),
                studyExternalId: $study,
                providerEventId: 'result-'.$study,
                payload: $this->gatePayload($study, 'result-'.$study),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function gatePayload(string $study, string $acuse): array
    {
        return [
            'id' => 'GDA-TEST',
            'status' => 'completed',
            'code' => [
                'coding' => [[
                    'code' => $study,
                    'display' => 'Study '.$study,
                ]],
            ],
            'GDA_menssage' => [
                'acuse' => $acuse,
            ],
        ];
    }

    /**
     * @return array{0: User, 1: TaxProfile, 2: Customer}
     */
    private function makeCustomerWithProfile(?int $customerId = null): array
    {
        if ($customerId !== null) {
            $customer = Customer::query()->findOrFail($customerId);
            $user = User::query()->findOrFail($customer->user_id);
            $profile = $customer->taxProfiles()->firstOrFail();

            return [$user, $profile, $customer];
        }

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
            'gda_order_id' => 'GDA-'.random_int(100000, 999999),
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
            'lab_order_event_receipts',
            'lab_order_event_states',
            'invoice_request_status_logs',
            'invoice_requests',
            'laboratory_purchase_items',
            'laboratory_notifications',
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
            $table->string('status')->default('pending');
            $table->string('gda_acuse')->nullable();
            $table->json('gda_response')->nullable();
            $table->string('gda_code_http')->nullable();
            $table->string('gda_mensaje')->nullable();
            $table->string('gda_description')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->string('results')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('gda_id')->nullable();
            $table->text('indications')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('laboratory_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->string('gda_order_id')->nullable();
            $table->string('notification_type')->nullable();
            $table->string('lineanegocio')->nullable();
            $table->string('gda_status')->nullable();
            $table->timestamp('results_received_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('email_error')->nullable();
            $table->timestamp('email_attempted_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->unsignedBigInteger('email_recipient_id')->nullable();
            $table->string('email_recipient_email')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->json('payload')->nullable();
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

        Schema::create('lab_order_event_states', function (Blueprint $table) {
            $table->id();
            $table->string('gda_order_id')->unique();
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained('laboratory_purchases')->nullOnDelete();
            $table->unsignedInteger('total_studies')->default(0);
            $table->unsignedInteger('sample_received_count')->default(0);
            $table->unsignedInteger('results_received_count')->default(0);
            $table->timestamp('sample_email_sent_at')->nullable();
            $table->timestamp('results_email_sent_at')->nullable();
            $table->timestamp('sample_tag_sent_at')->nullable();
            $table->timestamp('results_tag_sent_at')->nullable();
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_order_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_event_state_id')->constrained('lab_order_event_states')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('study_external_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('payload_hash', 64);
            $table->timestamps();

            $table->unique(
                ['lab_order_event_state_id', 'event_type', 'study_external_id'],
                'lab_evt_receipt_state_type_study_unique'
            );
            $table->unique('provider_event_id', 'lab_evt_receipt_provider_event_unique');
            $table->unique(
                ['lab_order_event_state_id', 'event_type', 'payload_hash'],
                'lab_evt_receipt_state_type_hash_unique'
            );
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'lab_order_event_receipts',
            'lab_order_event_states',
            'invoice_request_status_logs',
            'invoice_requests',
            'laboratory_purchase_items',
            'laboratory_notifications',
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
