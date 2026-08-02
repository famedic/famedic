<?php

namespace Tests\Feature\TaxProfiles;

use App\Actions\CreateInvoiceRequestAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\SetDefaultTaxProfileAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Policies\TaxProfilePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PF-1B.3: FK tax_profile_id, isUsed(), inmutabilidad de edición, sin sync cfdi.
 */
class TaxProfilePf1b3IsolatedTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-pf1b3-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'app.env' => 'testing',
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
            'taxregimes.uses' => [
                'G03' => 'Gastos en general.',
                'D01' => 'Honorarios médicos.',
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
    public function create_invoice_request_persiste_tax_profile_id_y_snapshot_cfdi(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile(['cfdi_use' => 'G03']);
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makeLaboratoryPurchase($customer);

        $request = app(CreateInvoiceRequestAction::class)($purchase, $profile, 'D01');

        $this->assertSame($profile->id, $request->tax_profile_id);
        $this->assertSame('D01', $request->cfdi_use);
        $this->assertSame('G03', $profile->fresh()->cfdi_use);
        $this->assertTrue($profile->fresh()->isUsed());
        $this->assertSame($profile->name, $request->name);
        $this->assertSame($profile->rfc, $request->rfc);
    }

    #[Test]
    public function farmacia_tambien_persiste_fk_sin_mutar_perfil(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile(['cfdi_use' => 'G03']);
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makePharmacyPurchase($customer);

        $request = app(CreateInvoiceRequestAction::class)($purchase, $profile, 'D01');

        $this->assertSame($profile->id, $request->tax_profile_id);
        $this->assertSame('D01', $request->cfdi_use);
        $this->assertSame('G03', $profile->fresh()->cfdi_use);
    }

    #[Test]
    public function perfil_soft_deleted_no_puede_usarse_en_solicitud(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makeLaboratoryPurchase($customer);
        $profile->delete();

        $this->expectException(InvalidArgumentException::class);
        app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
    }

    #[Test]
    public function perfil_ajeno_no_puede_vincularse_a_compra(): void
    {
        [, $profile] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        [, , $otherCustomer] = $this->makeCustomerWithProfile('other@test.local');
        $purchase = $this->makeLaboratoryPurchase($otherCustomer);

        $this->expectException(InvalidArgumentException::class);
        app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
    }

    #[Test]
    public function is_used_incluye_solicitud_soft_deleted(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');

        $request->delete();

        $this->assertTrue($profile->fresh()->isUsed());
        $this->assertTrue(
            $profile->invoiceRequests()->withTrashed()->whereKey($request->id)->exists()
        );
    }

    #[Test]
    public function perfil_usado_no_puede_actualizarse_por_policy_ni_action(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        $this->assertFalse((new TaxProfilePolicy)->update($user, $profile->fresh()));
        $this->assertTrue((new TaxProfilePolicy)->delete($user, $profile->fresh()));
        $this->assertTrue((new TaxProfilePolicy)->setDefault($user, $profile->fresh()));

        $this->expectException(InvalidArgumentException::class);
        app(UpdateTaxProfileAction::class)(
            name: 'Hack',
            rfc: $profile->rfc,
            zipcode: '64000',
            taxRegime: '612',
            cfdiUse: 'D01',
            taxProfile: $profile->fresh(),
        );
    }

    #[Test]
    public function perfil_usado_activo_puede_reutilizarse_y_marcarse_default_y_desactivarse(): void
    {
        [, $a, $customer] = $this->makeCustomerWithProfile();
        $a->forceFill(['is_default' => true])->save();
        Storage::put($a->fiscal_certificate, '%PDF');

        $b = $customer->taxProfiles()->create([
            'name' => 'Segundo',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b.pdf',
        ]);
        Storage::put('fiscal-certificates/b.pdf', '%PDF');

        $p1 = $this->makeLaboratoryPurchase($customer, 'o1');
        $r1 = app(CreateInvoiceRequestAction::class)($p1, $a, 'G03');
        $this->assertTrue($a->fresh()->isUsed());

        $p2 = $this->makeLaboratoryPurchase($customer, 'o2');
        $r2 = app(CreateInvoiceRequestAction::class)($p2, $a, 'D01');
        $this->assertSame($a->id, $r2->tax_profile_id);
        $this->assertSame('D01', $r2->cfdi_use);
        $this->assertSame('G03', $r1->fresh()->cfdi_use);

        $set = app(SetDefaultTaxProfileAction::class)($a->fresh());
        $this->assertTrue($set->is_default);

        app(DestroyTaxProfileAction::class)($a->fresh());
        $this->assertTrue($a->fresh()->trashed());
        $this->assertSame('Persona Fiscal', $r1->fresh()->name);
        $this->assertTrue($b->fresh()->is_default);
    }

    #[Test]
    public function http_update_de_usado_responde_403_y_destroy_sigue_ok(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('http@test.local');
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        $this->actingAs($user)
            ->put(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => 'X',
                'rfc' => 'MEBE931209BI2',
                'zipcode' => '64000',
                'tax_regime' => '612',
                'cfdi_use' => 'G03',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $profile->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->assertTrue($profile->fresh()->trashed());
    }

    #[Test]
    public function fallo_tras_validar_no_deja_solicitud_parcial(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        // Sin archivo en storage → falla antes/durante copy
        $purchase = $this->makeLaboratoryPurchase($customer);

        try {
            app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
            $this->fail('Se esperaba excepción');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('constancia', $e->getMessage());
        }

        $this->assertSame(0, InvoiceRequest::withTrashed()->count());
        $this->assertFalse($profile->fresh()->isUsed());
    }

    #[Test]
    public function present_for_patient_expone_is_used(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        $payload = $profile->fresh()->presentForPatient()->toArray();
        $this->assertFalse($payload['is_used']);

        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');
        $payloadUsed = $profile->fresh()->presentForPatient()->toArray();
        $this->assertTrue($payloadUsed['is_used']);
        $this->assertArrayNotHasKey('fiscal_certificate', $payloadUsed);
    }

    #[Test]
    public function null_tax_profile_id_historico_no_marca_usado(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);

        InvoiceRequest::query()->create([
            'tax_profile_id' => null,
            'invoice_requestable_type' => LaboratoryPurchase::class,
            'invoice_requestable_id' => $purchase->id,
            'name' => 'Hist',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/hist.pdf',
        ]);

        $this->assertFalse($profile->fresh()->isUsed());
        $this->assertTrue((new TaxProfilePolicy)->update(
            User::query()->find($customer->user_id),
            $profile
        ));
    }

    private function makeCustomerWithProfile(string|array $emailOrOverrides = 'owner@test.local', array $overrides = []): array
    {
        if (is_array($emailOrOverrides)) {
            $overrides = $emailOrOverrides;
            $email = 'owner-'.uniqid('', true).'@test.local';
        } else {
            $email = $emailOrOverrides;
        }

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
        ], $overrides));

        $user->setRelation('customer', $customer);

        return [$user, $profile, $customer];
    }

    private function makeLaboratoryPurchase(Customer $customer, ?string $gda = null): LaboratoryPurchase
    {
        return LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'gda_order_id' => $gda ?? (string) random_int(100000, 999999),
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
            $table->string('razon_social')->nullable();
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
