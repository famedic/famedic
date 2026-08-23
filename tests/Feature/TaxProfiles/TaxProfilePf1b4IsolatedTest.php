<?php

namespace Tests\Feature\TaxProfiles;

use App\Actions\CreateInvoiceRequestAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\SetDefaultTaxProfileAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Policies\TaxProfilePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PF-1B.4: payload is_used/is_default, presentCollectionForPatient (N+1),
 * Update→422, props Inertia listado/lab/farmacia, SetDefault/Destroy de usados.
 */
class TaxProfilePf1b4IsolatedTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-pf1b4-'.getmypid().'-'.uniqid('', true);
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
            'taxregimes.regimes' => [
                '612' => ['name' => 'Personas Físicas con Actividades Empresariales'],
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
        restoreMonolithicTestDatabase();

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
    public function present_for_patient_entrega_is_used_e_is_default_booleanos(): void
    {
        [, $profile] = $this->makeCustomerWithProfile();
        $profile->forceFill(['is_default' => true])->save();

        $payload = $profile->fresh()->presentForPatient()->toArray();

        $this->assertArrayHasKey('is_used', $payload);
        $this->assertArrayHasKey('is_default', $payload);
        $this->assertIsBool($payload['is_used']);
        $this->assertIsBool($payload['is_default']);
        $this->assertFalse($payload['is_used']);
        $this->assertTrue($payload['is_default']);
    }

    #[Test]
    public function perfil_usado_y_soft_deleted_request_marcan_is_used_true(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');

        $this->assertFalse($profile->fresh()->presentForPatient()->toArray()['is_used']);

        $request = app(CreateInvoiceRequestAction::class)(
            $this->makeLaboratoryPurchase($customer),
            $profile,
            'G03'
        );

        $this->assertTrue($profile->fresh()->presentForPatient()->toArray()['is_used']);

        $request->delete();

        $this->assertTrue($profile->fresh()->presentForPatient()->toArray()['is_used']);
        $this->assertTrue($profile->fresh()->isUsed());
    }

    #[Test]
    public function dos_perfiles_reflejan_is_used_por_su_propia_fk(): void
    {
        [$user, $a, $customer] = $this->makeCustomerWithProfile(['rfc' => 'MEBE931209BI2']);
        Storage::put($a->fiscal_certificate, '%PDF');

        $b = $customer->taxProfiles()->create([
            'name' => 'Segundo',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b-'.$user->id.'.pdf',
        ]);
        Storage::put($b->fiscal_certificate, '%PDF');

        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer, 'o-a'), $a, 'G03');

        $collection = TaxProfile::presentCollectionForPatient($customer)->keyBy('id');

        $this->assertTrue($collection[$a->id]->is_used);
        $this->assertFalse($collection[$b->id]->is_used);
    }

    #[Test]
    public function present_collection_no_incluye_perfil_ajeno_ni_soft_deleted(): void
    {
        [, $own, $customer] = $this->makeCustomerWithProfile('own@test.local');
        [, $foreign] = $this->makeCustomerWithProfile('foreign@test.local', ['rfc' => 'XAXX010101000']);

        $deleted = $customer->taxProfiles()->create([
            'name' => 'Borrado',
            'rfc' => 'CACX7605101P8',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/del.pdf',
        ]);
        $deleted->delete();

        $ids = TaxProfile::presentCollectionForPatient($customer)->pluck('id')->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    #[Test]
    public function with_exists_conserva_semantica_trashed_y_evita_exists_por_perfil(): void
    {
        [, $a, $customer] = $this->makeCustomerWithProfile(['rfc' => 'MEBE931209BI2']);
        Storage::put($a->fiscal_certificate, '%PDF');

        $b = $customer->taxProfiles()->create([
            'name' => 'B',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b2.pdf',
        ]);
        Storage::put($b->fiscal_certificate, '%PDF');

        $request = app(CreateInvoiceRequestAction::class)(
            $this->makeLaboratoryPurchase($customer, 'wex'),
            $a,
            'G03'
        );
        $request->delete();

        $selectQueries = [];
        DB::listen(function ($query) use (&$selectQueries) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'select') && str_contains($sql, 'tax_profiles')) {
                $selectQueries[] = $sql;
            }
        });

        $collection = TaxProfile::presentCollectionForPatient($customer)->keyBy('id');

        $this->assertTrue($collection[$a->id]->is_used);
        $this->assertFalse($collection[$b->id]->is_used);
        $this->assertCount(1, $selectQueries, 'Debe cargar perfiles en una sola consulta con exists embebido');
        $this->assertTrue(
            str_contains($selectQueries[0], 'exists') || str_contains($selectQueries[0], 'invoice_requests'),
            'La consulta debe incorporar la existencia de invoice_requests'
        );
    }

    #[Test]
    public function perfil_usado_puede_set_default_y_destroy_conservando_historial(): void
    {
        [, $a, $customer] = $this->makeCustomerWithProfile();
        $a->forceFill(['is_default' => true])->save();
        Storage::put($a->fiscal_certificate, '%PDF');

        $b = $customer->taxProfiles()->create([
            'name' => 'Alterno',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/alt.pdf',
            'is_default' => false,
        ]);
        Storage::put($b->fiscal_certificate, '%PDF');

        $purchase = $this->makeLaboratoryPurchase($customer);
        $invoiceRequest = app(CreateInvoiceRequestAction::class)($purchase, $a, 'D01');
        $snapshotName = $invoiceRequest->name;
        $snapshotCfdi = $invoiceRequest->cfdi_use;
        $certificatePath = $a->fiscal_certificate;

        $this->assertTrue($a->fresh()->isUsed());

        $set = app(SetDefaultTaxProfileAction::class)($a->fresh());
        $this->assertTrue($set->is_default);

        app(DestroyTaxProfileAction::class)($a->fresh());

        $this->assertTrue($a->fresh()->trashed());
        $this->assertTrue(Storage::exists($certificatePath));
        $this->assertSame($a->id, $invoiceRequest->fresh()->tax_profile_id);
        $this->assertSame($snapshotName, $invoiceRequest->fresh()->name);
        $this->assertSame($snapshotCfdi, $invoiceRequest->fresh()->cfdi_use);
        $this->assertTrue($b->fresh()->is_default);
    }

    #[Test]
    public function nueva_solicitud_acepta_perfil_usado_activo(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');

        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer, 'p1'), $profile, 'G03');
        $second = app(CreateInvoiceRequestAction::class)(
            $this->makeLaboratoryPurchase($customer, 'p2'),
            $profile,
            'D01'
        );

        $this->assertSame($profile->id, $second->tax_profile_id);
        $this->assertSame('D01', $second->cfdi_use);
    }

    #[Test]
    public function http_update_de_usado_sigue_403_por_policy(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('http403@test.local');
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        $this->assertFalse((new TaxProfilePolicy)->update($user, $profile->fresh()));

        $this->actingAs($user)
            ->put(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => 'X',
                'rfc' => 'MEBE931209BI2',
                'zipcode' => '64000',
                'tax_regime' => '612',
                'cfdi_use' => 'G03',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function perfil_no_usado_puede_abrirse_para_edicion_y_actualizarse(): void
    {
        $rfc = 'ABCD010101AAA';
        [$user, $profile] = $this->makeCustomerWithProfile('edit-ok@test.local', [
            'rfc' => $rfc,
            'tipo_persona' => 'fisica',
        ]);
        Storage::put($profile->fiscal_certificate, '%PDF');

        $this->assertTrue((new TaxProfilePolicy)->update($user, $profile->fresh()));
        $this->assertFalse($profile->fresh()->isUsed());

        $this->actingAs($user)
            ->get(route('tax-profiles.edit', ['tax_profile' => $profile->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TaxProfiles')
                ->has('taxProfile')
                ->where('taxProfile.id', $profile->id)
                ->where('taxProfile.rfc', $rfc)
                ->has('taxRegimes')
            );

        $this->actingAs($user)
            ->putJson(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => 'Nombre Actualizado',
                'rfc' => $rfc,
                'zipcode' => '64000',
                'tax_regime' => '612',
                'cfdi_use' => 'G03',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Perfil fiscal actualizado exitosamente.',
            ]);

        $this->assertSame('Nombre Actualizado', $profile->fresh()->name);
        $this->assertSame($rfc, $profile->fresh()->rfc);
    }

    #[Test]
    public function perfil_usado_no_puede_abrirse_para_edicion(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('edit-used@test.local');
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        $this->assertFalse((new TaxProfilePolicy)->update($user, $profile->fresh()));

        $this->actingAs($user)
            ->get(route('tax-profiles.edit', ['tax_profile' => $profile->id]))
            ->assertForbidden();
    }

    #[Test]
    public function cliente_ajeno_no_puede_editar_ni_set_default(): void
    {
        [, $profile] = $this->makeCustomerWithProfile('owner-edit@test.local');
        [$intruder] = $this->makeCustomerWithProfile('intruder-edit@test.local');

        $this->actingAs($intruder)
            ->get(route('tax-profiles.edit', ['tax_profile' => $profile->id]))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->put(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => 'Hack',
                'rfc' => 'ABC010101AAA',
                'zipcode' => '64000',
                'tax_regime' => '612',
                'cfdi_use' => 'G03',
            ])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => $profile->id]))
            ->assertForbidden();
    }

    #[Test]
    public function update_valida_campos_requeridos(): void
    {
        [$user, $profile] = $this->makeCustomerWithProfile('update-val@test.local', [
            'rfc' => 'ABC010101AAA',
        ]);

        $this->actingAs($user)
            ->putJson(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => '',
                'rfc' => 'INVALID',
                'zipcode' => '12',
                'tax_regime' => '',
                'cfdi_use' => 'NOPE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'rfc', 'zipcode', 'tax_regime', 'cfdi_use']);

        $this->assertSame('Persona Fiscal', $profile->fresh()->name);
    }

    #[Test]
    public function frontend_set_default_impide_doble_envio_mientras_procesa(): void
    {
        $source = file_get_contents(resource_path('js/Pages/TaxProfiles.jsx'));

        $this->assertStringContainsString('if (processing || taxProfile.is_default === true)', $source);
        $this->assertStringContainsString('disabled={processing}', $source);
        $this->assertStringContainsString('aria-busy={processing}', $source);
        $this->assertStringContainsString('router.visit(', $source);
        $this->assertStringContainsString('tax-profiles.edit', $source);
    }

    #[Test]
    public function action_update_de_usado_via_controller_responde_422_si_policy_permite(): void
    {
        $rfc = 'ABCD010101AAA';
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('http422@test.local', [
            'rfc' => $rfc,
            'tipo_persona' => 'fisica',
        ]);
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        Gate::before(fn () => true);

        $this->actingAs($user)
            ->putJson(route('tax-profiles.update', ['tax_profile' => $profile->id]), [
                'name' => 'Hack',
                'rfc' => $rfc,
                'zipcode' => '64000',
                'tax_regime' => '612',
                'cfdi_use' => 'G03',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Este perfil ya no se puede modificar porque fue utilizado en una solicitud de factura. Puedes usarlo en nuevas solicitudes o crear otro perfil con datos distintos.',
            ]);

        $this->assertSame('Persona Fiscal', $profile->fresh()->name);
    }

    #[Test]
    public function update_action_lanza_invalid_argument_con_mensaje_aprobado(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile();
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        try {
            app(UpdateTaxProfileAction::class)(
                name: 'Hack',
                rfc: $profile->rfc,
                zipcode: '64000',
                taxRegime: '612',
                cfdiUse: 'D01',
                taxProfile: $profile->fresh(),
            );
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ya no se puede modificar', $e->getMessage());
        }
    }

    #[Test]
    public function inertia_listado_recibe_is_used_e_is_default(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('list@test.local');
        $profile->forceFill(['is_default' => true])->save();
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $profile, 'G03');

        // Fuente de props del listado (TaxProfileController::patientTaxProfiles).
        // El GET Inertia completo depende de tablas transversales (notifications, etc.)
        // fuera del esquema aislado PF-1B.4; se valida el payload y el cableado.
        $props = TaxProfile::presentCollectionForPatient($customer);

        $this->assertCount(1, $props);
        $this->assertSame($profile->id, $props[0]->id);
        $this->assertTrue($props[0]->is_used);
        $this->assertTrue($props[0]->is_default);
        $this->assertArrayNotHasKey(
            'fiscal_certificate',
            $props[0]->toArray()
        );

        $this->assertStringContainsString(
            'presentCollectionForPatient',
            file_get_contents(app_path('Http/Controllers/TaxProfileController.php'))
        );
    }

    #[Test]
    public function loaders_laboratorio_y_farmacia_exponen_mismos_flags_que_listado(): void
    {
        [$user, $profile, $customer] = $this->makeCustomerWithProfile('shared-loaders@test.local');
        $profile->forceFill(['is_default' => true])->save();
        Storage::put($profile->fiscal_certificate, '%PDF');
        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer, 'shared-1'), $profile, 'G03');

        [, $foreign] = $this->makeCustomerWithProfile('shared-foreign@test.local', ['rfc' => 'XAXX010101000']);
        $deleted = $customer->taxProfiles()->create([
            'name' => 'Gone',
            'rfc' => 'CACX7605101P8',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/gone.pdf',
        ]);
        $deleted->delete();

        // Misma fuente que LaboratoryPurchaseController / OnlinePharmacyPurchaseController /
        // TaxProfileController::patientTaxProfiles (presentCollectionForPatient).
        $props = TaxProfile::presentCollectionForPatient($customer);

        $this->assertCount(1, $props);
        $this->assertSame($profile->id, $props[0]->id);
        $this->assertTrue($props[0]->is_used);
        $this->assertTrue($props[0]->is_default);
        $this->assertNotContains($foreign->id, $props->pluck('id'));
        $this->assertNotContains($deleted->id, $props->pluck('id'));

        $controllerSource = file_get_contents(app_path('Http/Controllers/LaboratoryPurchaseController.php'))
            ."\n".file_get_contents(app_path('Http/Controllers/OnlinePharmacyPurchaseController.php'))
            ."\n".file_get_contents(app_path('Http/Controllers/TaxProfileController.php'));

        $this->assertStringContainsString('TaxProfile::presentCollectionForPatient', $controllerSource);
        $this->assertStringContainsString(
            'presentCollectionForPatient',
            file_get_contents(app_path('Http/Controllers/LaboratoryPurchaseController.php'))
        );
        $this->assertStringContainsString(
            'presentCollectionForPatient',
            file_get_contents(app_path('Http/Controllers/OnlinePharmacyPurchaseController.php'))
        );
    }

    #[Test]
    public function http_set_default_y_destroy_de_usado_siguen_ok(): void
    {
        [$user, $a, $customer] = $this->makeCustomerWithProfile('ops@test.local');
        Storage::put($a->fiscal_certificate, '%PDF');

        $b = $customer->taxProfiles()->create([
            'name' => 'B',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/ops-b.pdf',
            'is_default' => true,
        ]);
        Storage::put($b->fiscal_certificate, '%PDF');

        app(CreateInvoiceRequestAction::class)($this->makeLaboratoryPurchase($customer), $a, 'G03');

        $this->actingAs($user)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => $a->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->assertTrue($a->fresh()->is_default);
        $this->assertFalse($b->fresh()->is_default);

        $this->actingAs($user)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $a->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->assertTrue($a->fresh()->trashed());
        $this->assertTrue($b->fresh()->is_default);
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
            'notifications',
            'invoices',
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
            $table->string('hash_constancia')->nullable();
            $table->string('codigo_postal_original')->nullable();
            $table->string('regimen_fiscal_original')->nullable();
            $table->string('tipo_persona')->nullable();
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
            $table->morphs('invoice_requestable');
            $table->string('name');
            $table->string('rfc');
            $table->string('zipcode');
            $table->string('tax_regime');
            $table->string('cfdi_use');
            $table->string('fiscal_certificate');
            $table->timestamps();
            $table->softDeletes();
        });

        // Requerido por TaxProfileController::patientInvoicesPaginator en index Inertia.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('invoiceable');
            $table->string('invoice')->nullable();
            $table->string('invoice_xml')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Requerido por HandleInertiaRequests::getInAppNotificationFeed en GET Inertia.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'notifications',
            'invoices',
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
