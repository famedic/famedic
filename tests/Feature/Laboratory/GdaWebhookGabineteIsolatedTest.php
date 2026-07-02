<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratory\CreateNotificationAction;
use App\Actions\Laboratory\FindPurchaseAction;
use App\Actions\Laboratory\FindReferencesAction;
use App\Actions\Laboratory\ProcessNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Laboratory\LaboratoryWebhookController;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\GDA\GdaWebhookPayloadResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests aislados del webhook GDA (gabinete) sin migraciones históricas completas.
 */
class GdaWebhookGabineteIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->bootstrapIsolatedGdaWebhookSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedGdaWebhookSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function crea_notificacion_de_gabinete_sin_guardar_etiqueta_en_gda_consecutivo(): void
    {
        NotificationFacade::fake();

        $purchase = $this->seedGabinetePurchase(['gda_order_id' => 'GZ0L000414']);
        $payload = $this->gabinetePayload('GZ0L000414', '414', (string) $purchase->id);

        $references = app(FindReferencesAction::class)->execute($payload);
        $request = Request::create('/api/laboratory/webhook/notifications', 'POST', $payload);

        $notification = app(CreateNotificationAction::class)->execute($payload, $request, $references);

        $this->assertSame('GZ0L000414', $notification->gda_order_id);
        $this->assertSame(414, $notification->gda_consecutivo);
        $this->assertSame((string) $purchase->id, $notification->gda_external_id);
        $this->assertSame($payload['GDA_menssage']['acuse'], $notification->gda_acuse);
        $this->assertSame($purchase->id, $notification->laboratory_purchase_id);
        $this->assertIsArray($notification->payload);
        $this->assertSame('GZ0L000414', $notification->payload['id']);
    }

    #[Test]
    public function encuentra_compra_de_gabinete_por_gda_order_id(): void
    {
        $purchase = $this->seedGabinetePurchase(['gda_order_id' => 'GZ0L000414']);
        $resolved = app(GdaWebhookPayloadResolver::class)->resolve(
            $this->gabinetePayload('GZ0L000414', '414', (string) $purchase->id)
        );

        $found = app(FindPurchaseAction::class)->execute($resolved);

        $this->assertNotNull($found);
        $this->assertSame($purchase->id, $found->id);
    }

    #[Test]
    public function encuentra_compra_por_gda_consecutivo_cuando_solo_existe_consecutivo_numerico(): void
    {
        $purchase = $this->seedGabinetePurchase([
            'gda_order_id' => 'legacy-folio',
            'gda_consecutivo' => 414,
        ]);

        $resolved = app(GdaWebhookPayloadResolver::class)->resolve([
            'id' => 'LEGACY-LABEL',
            'requisition' => ['value' => '999999'],
            'code' => ['coding' => [['infogda_orden' => '414']]],
        ]);

        $found = app(FindPurchaseAction::class)->execute($resolved);

        $this->assertNotNull($found);
        $this->assertSame($purchase->id, $found->id);
    }

    #[Test]
    public function webhook_de_gabinete_responde_201_por_http(): void
    {
        NotificationFacade::fake();

        $this->mock(ProcessNotificationAction::class, function ($mock) {
            $mock->shouldReceive('execute')->once();
        });

        $purchase = $this->seedGabinetePurchase(['gda_order_id' => 'GZ0L000515']);
        $payload = $this->gabinetePayload('GZ0L000515', '515', (string) $purchase->id);

        $response = $this->postJson('/api/laboratory/webhook/notifications', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('laboratory_notifications', [
            'gda_order_id' => 'GZ0L000515',
            'gda_consecutivo' => 515,
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
        ]);
    }

    #[Test]
    public function webhook_de_laboratorio_normal_realista_mantiene_consecutivo_por_service_request_id(): void
    {
        NotificationFacade::fake();

        $this->mock(ProcessNotificationAction::class, function ($mock) {
            $mock->shouldReceive('execute')->once();
        });

        $purchase = $this->seedGabinetePurchase([
            'gda_order_id' => '24642071',
            'gda_consecutivo' => 24642071,
        ]);

        $payload = $this->normalLabPayload();

        $response = $this->postJson('/api/laboratory/webhook/notifications', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('laboratory_notifications', [
            'gda_order_id' => '24642071',
            'gda_consecutivo' => 24642071,
            'gda_external_id' => 'HD0L001392',
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
        ]);

        $notification = LaboratoryNotification::query()->latest('id')->first();

        $this->assertIsArray($notification->payload);
        $this->assertSame('24642071', $notification->payload['id']);
        $this->assertSame('1392', $notification->payload['code']['coding'][0]['infogda_orden']);
    }

    #[Test]
    public function encuentra_compra_de_laboratorio_normal_por_gda_order_id_numerico(): void
    {
        $purchase = $this->seedGabinetePurchase([
            'gda_order_id' => '24642071',
            'gda_consecutivo' => 24642071,
        ]);

        $resolved = app(GdaWebhookPayloadResolver::class)->resolve($this->normalLabPayload());
        $found = app(FindPurchaseAction::class)->execute($resolved);

        $this->assertNotNull($found);
        $this->assertSame($purchase->id, $found->id);
        $this->assertSame(24642071, $resolved['gda_consecutivo']);
    }

    #[Test]
    public function webhook_de_laboratorio_normal_con_id_numerico_sigue_funcionando(): void
    {
        NotificationFacade::fake();

        $this->mock(ProcessNotificationAction::class, function ($mock) {
            $mock->shouldReceive('execute')->once();
        });

        $purchase = $this->seedGabinetePurchase([
            'gda_order_id' => '412924',
            'gda_consecutivo' => 412924,
        ]);
        $payload = $this->labPayload('412924', (string) $purchase->id);

        $response = $this->postJson('/api/laboratory/webhook/notifications', $payload);

        $response->assertCreated();

        $notification = LaboratoryNotification::query()->latest('id')->first();

        $this->assertSame('412924', $notification->gda_order_id);
        $this->assertSame(412924, $notification->gda_consecutivo);
        $this->assertSame($purchase->id, $notification->laboratory_purchase_id);
    }

    #[Test]
    public function controlador_webhook_esta_registrado(): void
    {
        $this->assertTrue(class_exists(LaboratoryWebhookController::class));
    }

    private function normalLabPayload(): array
    {
        return [
            'header' => [
                'lineanegocio' => 'Notificaion-Resultados',
                'registro' => now()->toIso8601String(),
                'marca' => 5,
                'token' => '',
            ],
            'resourceType' => 'ServiceRequest',
            'id' => '24642071',
            'requisition' => [
                'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                'value' => 'HD0L001392',
                'convenio' => 17479,
            ],
            'status' => 'completed',
            'intent' => 'order',
            'code' => [
                'coding' => [[
                    'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                    'code' => '510770',
                    'display' => 'PERFIL HORMONAL 5',
                    'infogda_status' => 'completed',
                    'infogda_cexamen' => 510770,
                    'infogda_orden' => '1392',
                    'infogda_muestras' => [[
                        'infogda_etiqueta' => 'HD0L001392OQ',
                        'infogda_contenedoracronim' => 'TTOG',
                    ]],
                ]],
            ],
            'subject' => ['reference' => 'Patient/13224379'],
            'GDA_menssage' => [
                'codeHttp' => 0,
                'mensaje' => 'success',
                'descripcion' => '',
                'acuse' => (string) Str::uuid(),
            ],
        ];
    }

    private function gabinetePayload(string $id, string $orden, ?string $requisitionValue = null): array
    {
        return [
            'header' => [
                'lineanegocio' => 'Notificaion-Resultados',
                'registro' => now()->toIso8601String(),
                'marca' => 1,
                'token' => '',
            ],
            'resourceType' => 'ServiceRequest',
            'id' => $id,
            'requisition' => [
                'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                'value' => $requisitionValue ?? '1868',
                'convenio' => 17682,
            ],
            'status' => 'completed',
            'intent' => 'order',
            'priority' => 'routine',
            'code' => [
                'coding' => [[
                    'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                    'code' => '165128',
                    'display' => 'RM PELVIS',
                    'infogda_status' => 'completed',
                    'infogda_orden' => $orden,
                    'infogda_muestras' => [[
                        'infogda_etiqueta' => $id,
                        'infogda_contenedor' => '14X17',
                        'infogda_contenedoracronim' => 'GAB',
                    ]],
                ]],
            ],
            'orderdetail' => 'Liberado',
            'quantityQuantity' => '1',
            'subject' => ['reference' => 'Patient/13224379'],
            'GDA_menssage' => [
                'codeHttp' => 0,
                'mensaje' => 'success',
                'descripcion' => '',
                'acuse' => (string) Str::uuid(),
            ],
        ];
    }

    private function labPayload(string $id, string $requisitionValue): array
    {
        $payload = $this->gabinetePayload($id, $id, $requisitionValue);
        $payload['code']['coding'][0]['infogda_orden'] = $id;
        unset($payload['code']['coding'][0]['infogda_muestras']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedGabinetePurchase(array $overrides = []): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente',
            'email' => 'gda-webhook-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'GZ0L000414',
            'gda_consecutivo' => null,
            'name' => 'Paciente',
            'paternal_lastname' => 'Gabinete',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'street' => 'Calle Test',
            'number' => '100',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 50_000,
        ], $overrides));
    }

    private function bootstrapIsolatedGdaWebhookSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_quotes',
            'laboratory_purchase_items',
            'laboratory_purchases',
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
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
            $table->bigInteger('gda_consecutivo')->nullable();
            $table->string('gda_acuse')->nullable();
            $table->json('gda_response')->nullable();
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
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('results_downloaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained();
            $table->string('gda_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('laboratory_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->string('gda_order_id')->nullable();
            $table->bigInteger('gda_consecutivo')->nullable();
            $table->string('gda_external_id')->nullable();
            $table->string('gda_acuse')->nullable();
            $table->json('gda_response')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->foreignId('laboratory_quote_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('contact_id')->nullable();
            $table->string('gda_order_id')->nullable();
            $table->bigInteger('gda_consecutivo')->nullable();
            $table->string('gda_external_id')->nullable();
            $table->string('gda_acuse')->nullable();
            $table->string('notification_type');
            $table->string('status');
            $table->string('gda_status')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('lineanegocio')->nullable();
            $table->json('payload');
            $table->json('gda_message')->nullable();
            $table->longText('results_pdf_base64')->nullable();
            $table->timestamp('results_received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('provider_event_id')->nullable()->unique();
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['lab_order_event_state_id', 'event_type', 'study_external_id'], 'lab_evt_receipt_state_type_study_unique');
            $table->unique(['lab_order_event_state_id', 'event_type', 'payload_hash'], 'lab_evt_receipt_state_type_hash_unique');
        });

        Schema::enableForeignKeyConstraints();
    }

    private function tearDownIsolatedGdaWebhookSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_quotes',
            'laboratory_purchase_items',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
