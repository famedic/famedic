<?php

namespace Tests\Feature\TaxProfiles;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreInvoiceRequestRequest as LabStoreInvoiceRequestRequest;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyPurchases\StoreInvoiceRequestRequest as PharmacyStoreInvoiceRequestRequest;
use App\Http\Requests\TaxProfiles\UpdateTaxProfileRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Policies\TaxProfilePolicy;
use App\Services\ConstanciaFiscalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Esquema aislado para PF-1A: autorización, extracción, cfdi_use, snapshot y props.
 */
class TaxProfilePf1aIsolatedTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-pf1a-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
            'taxregimes.uses' => [
                'G03' => 'Gastos en general.',
                'D01' => 'Honorarios médicos, dentales y gastos hospitalarios.',
            ],
        ]);
        Storage::forgetDisk('local');

        $this->bootstrapSchema();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
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
    public function propietario_puede_actualizar_su_perfil_segun_policy(): void
    {
        [$owner, $profile] = $this->makeCustomerWithTaxProfile();

        $this->assertTrue((new TaxProfilePolicy)->update($owner, $profile));
        $this->assertTrue((new TaxProfilePolicy)->view($owner, $profile));
        $this->assertTrue((new TaxProfilePolicy)->delete($owner, $profile));
    }

    #[Test]
    public function otro_paciente_no_puede_ver_actualizar_ni_eliminar_perfil_ajeno(): void
    {
        [, $profile] = $this->makeCustomerWithTaxProfile();
        [$other] = $this->makeCustomerWithTaxProfile('other@test.local');

        $policy = new TaxProfilePolicy;

        $this->assertFalse($policy->view($other, $profile));
        $this->assertFalse($policy->update($other, $profile));
        $this->assertFalse($policy->delete($other, $profile));
    }

    #[Test]
    public function update_tax_profile_request_exige_autorizacion_por_policy(): void
    {
        [$owner, $profile] = $this->makeCustomerWithTaxProfile();
        [$intruder] = $this->makeCustomerWithTaxProfile('intruder@test.local');

        $ownerRequest = UpdateTaxProfileRequest::create('/tax-profiles/'.$profile->id, 'PUT', [
            'name' => 'Actualizado',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
        ]);
        $ownerRequest->setUserResolver(fn () => $owner);
        $ownerRequest->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('PUT', 'tax-profiles/{tax_profile}', []), function ($route) use ($profile) {
            $route->bind(Request::create('/'));
            $route->setParameter('tax_profile', $profile);
        }));

        $this->assertTrue($ownerRequest->authorize());

        $intruderRequest = UpdateTaxProfileRequest::create('/tax-profiles/'.$profile->id, 'PUT', [
            'name' => 'Hack',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
        ]);
        $intruderRequest->setUserResolver(fn () => $intruder);
        $intruderRequest->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('PUT', 'tax-profiles/{tax_profile}', []), function ($route) use ($profile) {
            $route->bind(Request::create('/'));
            $route->setParameter('tax_profile', $profile);
        }));

        $this->assertFalse($intruderRequest->authorize());
    }

    #[Test]
    public function customer_id_no_esta_en_fillable_del_tax_profile(): void
    {
        $this->assertNotContains('customer_id', (new TaxProfile)->getFillable());
    }

    #[Test]
    public function extraccion_fallida_no_devuelve_datos_ficticios_ni_success_true(): void
    {
        [$user] = $this->makeCustomerWithTaxProfile();
        $this->actingAs($user);

        $this->app->instance(ConstanciaFiscalService::class, new class extends ConstanciaFiscalService
        {
            public function __construct() {}

            public function procesarConstancia($archivo): array
            {
                return [
                    'success' => false,
                    'error' => 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
                ];
            }
        });

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'data' => null,
        ]);

        $payload = $response->json();
        $this->assertArrayNotHasKey('warning', $payload);
        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('XAXX010101000', $encoded);
        $this->assertStringNotContainsString('PUBLICO EN GENERAL', $encoded);
        $this->assertStringNotContainsString('stack', strtolower($encoded));
    }

    #[Test]
    public function excepcion_del_parser_devuelve_error_seguro_sin_crear_perfil(): void
    {
        [$user] = $this->makeCustomerWithTaxProfile();
        $this->actingAs($user);
        $before = TaxProfile::count();

        $mock = \Mockery::mock(ConstanciaFiscalService::class);
        $mock->shouldReceive('procesarConstancia')
            ->once()
            ->andThrow(new \RuntimeException('texto secreto del PDF ABC123'));
        $this->app->instance(ConstanciaFiscalService::class, $mock);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'data' => null,
        ]);
        $encoded = $response->getContent();
        $this->assertStringNotContainsString('texto secreto', $encoded);
        $this->assertStringNotContainsString('ABC123', $encoded);
        $this->assertSame($before, TaxProfile::count());
    }

    #[Test]
    public function logs_de_fallo_de_extraccion_no_incluyen_rfc_ni_texto_pdf(): void
    {
        Log::spy();

        $service = app(ConstanciaFiscalService::class);
        $ref = new \ReflectionClass($service);
        $parserProp = $ref->getProperty('parser');
        $parserProp->setAccessible(true);
        $parserProp->setValue($service, new class
        {
            public function parseContent($content)
            {
                throw new \RuntimeException('Fallo con RFC MEBE931209BI2 y texto fiscal');
            }
        });

        $result = $service->procesarConstancia(
            UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf')
        );

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('data', $result);

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            $encoded = json_encode([$message, $context]);

            return ! str_contains($encoded, 'MEBE931209BI2')
                && ! str_contains($encoded, 'texto fiscal')
                && ($context['exception_class'] ?? null) !== null;
        });
    }

    #[Test]
    public function invoice_present_for_patient_oculta_paths_y_expone_urls(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithTaxProfileFull();
        $purchase = $this->makeLaboratoryPurchase($customer);

        $invoice = Invoice::query()->create([
            'invoiceable_type' => LaboratoryPurchase::class,
            'invoiceable_id' => $purchase->id,
            'invoice' => 'invoices/privado-secreto.pdf',
            'invoice_xml' => 'invoices/privado-secreto.xml',
        ]);

        $payload = $invoice->presentForPatient()->toArray();

        $this->assertArrayNotHasKey('invoice', $payload);
        $this->assertArrayNotHasKey('invoice_xml', $payload);
        $this->assertTrue($payload['has_invoice_xml']);
        $this->assertSame(route('invoice', ['invoice' => $invoice->id]), $payload['invoice_url']);
        $this->assertSame(route('invoice.xml', ['invoice' => $invoice->id]), $payload['invoice_xml_url']);
        $this->assertStringNotContainsString('privado-secreto', json_encode($payload));
    }

    #[Test]
    public function factura_historica_sin_xml_no_rompe_props(): void
    {
        [, , $customer] = $this->makeCustomerWithTaxProfileFull();
        $purchase = $this->makeLaboratoryPurchase($customer);

        $invoice = Invoice::query()->create([
            'invoiceable_type' => LaboratoryPurchase::class,
            'invoiceable_id' => $purchase->id,
            'invoice' => 'invoices/solo-pdf.pdf',
            'invoice_xml' => null,
        ]);

        $payload = $invoice->presentForPatient()->toArray();

        $this->assertFalse($payload['has_invoice_xml']);
        $this->assertNull($payload['invoice_xml_url']);
        $this->assertNotEmpty($payload['invoice_url']);
        $this->assertArrayNotHasKey('invoice', $payload);
    }

    #[Test]
    public function laboratorio_y_farmacia_rechazan_cfdi_use_manipulado(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithTaxProfileFull();
        $labPurchase = $this->makeLaboratoryPurchase($customer);
        $pharmacyPurchase = $this->makePharmacyPurchase($customer);

        foreach ([
            LabStoreInvoiceRequestRequest::class => $labPurchase,
            PharmacyStoreInvoiceRequestRequest::class => $pharmacyPurchase,
        ] as $requestClass => $purchase) {
            $request = $requestClass::create('/', 'POST', [
                'tax_profile' => $profile->id,
                'cfdi_use' => 'ZZ99',
            ]);
            $request->setUserResolver(fn () => $user);
            if ($requestClass === LabStoreInvoiceRequestRequest::class) {
                $request->laboratory_purchase = $purchase;
            } else {
                $request->online_pharmacy_purchase = $purchase;
            }

            $validator = Validator::make($request->all(), $request->rules(), $request->messages());
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey('cfdi_use', $validator->errors()->toArray());
        }
    }

    #[Test]
    public function farmacia_persiste_cfdi_use_elegido_en_el_snapshot(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfileFull('pharmacy@test.local', ['cfdi_use' => 'G03']);
        $purchase = $this->makePharmacyPurchase($customer);

        Storage::put($profile->fiscal_certificate, '%PDF-fake');

        $invoiceRequest = app(CreateInvoiceRequestAction::class)(
            $purchase,
            $profile,
            'D01',
        );

        $this->assertSame('D01', $invoiceRequest->cfdi_use);
        $this->assertSame('G03', $profile->fresh()->cfdi_use);
    }

    #[Test]
    public function laboratorio_conserva_snapshot_aunque_se_edite_el_perfil_despues(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfileFull('snapshot@test.local', [
            'name' => 'Original SA',
            'rfc' => 'ORI010101ABC',
            'cfdi_use' => 'G03',
        ]);
        $purchase = $this->makeLaboratoryPurchase($customer);
        Storage::put($profile->fiscal_certificate, '%PDF-fake');

        $invoiceRequest = app(CreateInvoiceRequestAction::class)(
            $purchase,
            $profile,
            'G03',
        );

        $profile->update([
            'name' => 'Nombre Nuevo',
            'rfc' => 'NUE010101XYZ',
            'cfdi_use' => 'D01',
        ]);

        $invoiceRequest->refresh();

        $this->assertSame('Original SA', $invoiceRequest->name);
        $this->assertSame('ORI010101ABC', $invoiceRequest->rfc);
        $this->assertSame('G03', $invoiceRequest->cfdi_use);
    }

    #[Test]
    public function soft_delete_del_perfil_no_elimina_snapshot(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfileFull();
        $purchase = $this->makeLaboratoryPurchase($customer);
        Storage::put($profile->fiscal_certificate, '%PDF-fake');

        $invoiceRequest = app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
        $profile->delete();

        $this->assertNotNull(InvoiceRequest::query()->find($invoiceRequest->id));
        $this->assertSame($invoiceRequest->rfc, InvoiceRequest::query()->find($invoiceRequest->id)->rfc);
    }

    #[Test]
    public function otro_paciente_no_puede_usar_perfil_ajeno_en_solicitud(): void
    {
        [, $foreignProfile] = $this->makeCustomerWithTaxProfile();
        [$user, , $customer] = $this->makeCustomerWithTaxProfileFull('owner2@test.local');
        $purchase = $this->makeLaboratoryPurchase($customer);

        $request = LabStoreInvoiceRequestRequest::create('/', 'POST', [
            'tax_profile' => $foreignProfile->id,
            'cfdi_use' => 'G03',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->laboratory_purchase = $purchase;

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tax_profile', $validator->errors()->toArray());
    }

    #[Test]
    public function present_for_patient_oculta_path_y_nombre_interno_de_constancia(): void
    {
        $secretPath = 'fiscal-certificates/synth-secret-abc.pdf';

        [, $profile] = $this->makeCustomerWithTaxProfileFull('cert-props@test.local', [
            'fiscal_certificate' => $secretPath,
        ]);

        $payload = $profile->presentForPatient()->toArray();
        $json = json_encode($payload);

        $this->assertArrayNotHasKey('fiscal_certificate', $payload);
        $this->assertArrayNotHasKey('hash_constancia', $payload);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertArrayNotHasKey('certificate_url', $payload);
        $this->assertStringNotContainsString($secretPath, $json);
        $this->assertStringNotContainsString('synth-secret-abc', $json);
        $this->assertStringNotContainsString('fiscal-certificates/', $json);

        $this->assertSame($profile->id, $payload['id']);
        $this->assertSame('Persona Fiscal', $payload['name']);
        $this->assertSame('MEBE931209BI2', $payload['rfc']);
        $this->assertSame('64000', $payload['zipcode']);
        $this->assertSame('612', $payload['tax_regime']);
        $this->assertSame('G03', $payload['cfdi_use']);
        $this->assertArrayHasKey('formatted_tax_regime', $payload);
        $this->assertArrayHasKey('formatted_cfdi_use', $payload);
    }

    #[Test]
    public function perfil_sin_constancia_no_rompe_presentacion_paciente(): void
    {
        [, $profile] = $this->makeCustomerWithTaxProfileFull('no-cert@test.local', [
            'fiscal_certificate' => null,
        ]);

        $payload = $profile->presentForPatient()->toArray();

        $this->assertArrayNotHasKey('fiscal_certificate', $payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('rfc', $payload);
    }

    #[Test]
    public function modelo_sin_present_for_patient_conserva_fiscal_certificate_para_admin(): void
    {
        $path = 'fiscal-certificates/admin-keep.pdf';
        [, $profile] = $this->makeCustomerWithTaxProfileFull('admin-keep@test.local', [
            'fiscal_certificate' => $path,
        ]);

        $this->assertSame($path, $profile->fresh()->fiscal_certificate);
        $this->assertArrayHasKey('fiscal_certificate', $profile->fresh()->toArray());
    }

    #[Test]
    public function descarga_constancia_ajena_no_autorizada_por_policy(): void
    {
        [, $profile] = $this->makeCustomerWithTaxProfileFull('owner-dl@test.local');
        [$other] = $this->makeCustomerWithTaxProfileFull('intruder-dl@test.local');

        $this->assertFalse((new TaxProfilePolicy)->view($other, $profile));
    }

    #[Test]
    public function descarga_constancia_inexistente_responde_404(): void
    {
        [$user, $profile] = $this->makeCustomerWithTaxProfileFull('missing-dl@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/missing-synth.pdf',
        ]);

        $this->actingAs($user);

        $response = $this->get(route('tax-profiles.fiscal-certificate', [
            'tax_profile' => $profile->id,
        ]));

        $response->assertNotFound();
        $this->assertStringNotContainsString('missing-synth.pdf', $response->getContent());
        $this->assertStringNotContainsString('fiscal-certificates/', $response->getContent());
    }

    #[Test]
    public function descarga_constancia_sin_path_responde_404(): void
    {
        [$user, $profile] = $this->makeCustomerWithTaxProfileFull('null-dl@test.local', [
            'fiscal_certificate' => null,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('tax-profiles.fiscal-certificate', [
            'tax_profile' => $profile->id,
        ]));

        $response->assertNotFound();
    }

    private function makeCustomerWithTaxProfile(string $email = 'owner@test.local', array $profileOverrides = []): array
    {
        return $this->makeCustomerWithTaxProfileFull($email, $profileOverrides);
    }

    private function makeCustomerWithTaxProfileFull(string $email = 'owner@test.local', array $profileOverrides = []): array
    {
        $user = User::query()->create([
            'name' => 'Paciente',
            'email' => $email,
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'customerable_type' => 'App\\Models\\RegularAccount',
            'customerable_id' => 1,
        ]);

        $profile = $customer->taxProfiles()->create(array_merge([
            'name' => 'Persona Fiscal',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/test-'.$user->id.'.pdf',
        ], $profileOverrides));

        $user->setRelation('customer', $customer);

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

    private function makePharmacyPurchase(Customer $customer): OnlinePharmacyPurchase
    {
        return OnlinePharmacyPurchase::query()->create([
            'customer_id' => $customer->id,
            'vitau_order_id' => (string) random_int(100000, 999999),
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'subtotal_cents' => 5000,
            'shipping_price_cents' => 0,
            'tax_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 5000,
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'invoice_requests',
            'invoices',
            'online_pharmacy_purchases',
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
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
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

        Schema::create('online_pharmacy_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('vitau_order_id')->nullable();
            $table->string('name')->default('Paciente');
            $table->string('paternal_lastname')->default('Prueba');
            $table->string('maternal_lastname')->default('Test');
            $table->string('phone')->default('8112345678');
            $table->string('phone_country')->default('MX');
            $table->string('street')->default('Calle');
            $table->string('number')->default('1');
            $table->string('neighborhood')->default('Centro');
            $table->string('state')->default('NL');
            $table->string('city')->default('Monterrey');
            $table->string('zipcode')->default('64000');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('shipping_price_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->date('expected_delivery_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('invoiceable');
            $table->string('invoice');
            $table->string('invoice_xml')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_profile_id')
                ->nullable()
                ->constrained('tax_profiles')
                ->nullOnDelete();
            $table->morphs('invoice_requestable', 'invoice_requestable_index');
            $table->string('name');
            $table->string('rfc');
            $table->string('zipcode');
            $table->string('tax_regime');
            $table->string('cfdi_use');
            $table->string('fiscal_certificate');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'invoice_requests',
            'invoices',
            'online_pharmacy_purchases',
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
