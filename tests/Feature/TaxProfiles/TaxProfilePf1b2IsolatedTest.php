<?php

namespace Tests\Feature\TaxProfiles;

use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\EnsureActiveDefaultTaxProfileAction;
use App\Actions\TaxProfiles\SetDefaultTaxProfileAction;
use App\Models\Customer;
use App\Models\TaxProfile;
use App\Models\User;
use App\Policies\TaxProfilePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite portable SQLite PF-1B.2: create / set-default / deactivate + locks.
 * No afirma UNIQUE MySQL.
 */
class TaxProfilePf1b2IsolatedTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-pf1b2-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'app.env' => 'testing',
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
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
    public function primer_perfil_activo_queda_predeterminado(): void
    {
        [$user] = $this->makeCustomer('first@test.local');
        Auth::login($user);

        $profile = app(CreateTaxProfileAction::class)(
            name: 'Primero',
            rfc: 'MEBE931209BI2',
            zipcode: '64000',
            taxRegime: '612',
            cfdiUse: 'G03',
            fiscalCertificate: UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        );

        $this->assertTrue($profile->fresh()->is_default);
        $this->assertSame(1, TaxProfile::query()->where('customer_id', $user->customer->id)->where('is_default', true)->count());
    }

    #[Test]
    public function segundo_perfil_no_desplaza_predeterminado_existente(): void
    {
        [$user, $first] = $this->makeCustomerWithProfile('second@test.local');
        $first->forceFill(['is_default' => true])->save();
        Auth::login($user);

        $second = app(CreateTaxProfileAction::class)(
            name: 'Segundo',
            rfc: 'XAXX010101000',
            zipcode: '64000',
            taxRegime: '612',
            cfdiUse: 'G03',
            fiscalCertificate: UploadedFile::fake()->create('c2.pdf', 100, 'application/pdf'),
        );

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }

    #[Test]
    public function create_con_activos_sin_default_repara_existente_y_nuevo_no_default(): void
    {
        [$user, $older, $customer] = $this->makeCustomerWithProfile('repair@test.local');
        $older->forceFill([
            'is_default' => false,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $newerExisting = $customer->taxProfiles()->create([
            'name' => 'Existente reciente',
            'rfc' => 'CACX7605101P8',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/newer.pdf',
        ]);
        $newerExisting->forceFill([
            'is_default' => false,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->save();

        Auth::login($user);

        $created = app(CreateTaxProfileAction::class)(
            name: 'Recien creado',
            rfc: 'XAXX010101000',
            zipcode: '64000',
            taxRegime: '612',
            cfdiUse: 'G03',
            fiscalCertificate: UploadedFile::fake()->create('c3.pdf', 100, 'application/pdf'),
        );

        $this->assertTrue($newerExisting->fresh()->is_default);
        $this->assertFalse($older->fresh()->is_default);
        $this->assertFalse($created->fresh()->is_default);
    }

    #[Test]
    public function set_default_cambia_predeterminado_de_forma_atomica_e_idempotente(): void
    {
        [$user, $a, $customer] = $this->makeCustomerWithProfile('set@test.local');
        $a->forceFill(['is_default' => true])->save();

        $b = $customer->taxProfiles()->create([
            'name' => 'B',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b.pdf',
        ]);
        $b->forceFill(['is_default' => false])->save();

        $action = app(SetDefaultTaxProfileAction::class);
        $result = $action($b);

        $this->assertTrue($result->is_default);
        $this->assertFalse($a->fresh()->is_default);
        $this->assertSame(1, TaxProfile::query()->where('customer_id', $customer->id)->where('is_default', true)->count());

        $again = $action($b->fresh());
        $this->assertTrue($again->is_default);
        $this->assertSame($user->id, $user->id);
    }

    #[Test]
    public function set_default_rechaza_perfil_ajeno_por_policy(): void
    {
        [, $profile] = $this->makeCustomerWithProfile('owner-set@test.local');
        [$other] = $this->makeCustomerWithProfile('intruder-set@test.local');

        $this->assertFalse((new TaxProfilePolicy)->setDefault($other, $profile));
    }

    #[Test]
    public function deactivate_no_default_conserva_predeterminado_y_archivo(): void
    {
        [, $default, $customer] = $this->makeCustomerWithProfile('keep@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/keep-default.pdf',
        ]);
        $default->forceFill(['is_default' => true])->save();
        Storage::put('fiscal-certificates/keep-default.pdf', 'pdf');
        Storage::put('fiscal-certificates/other.pdf', 'pdf');

        $other = $customer->taxProfiles()->create([
            'name' => 'Other',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/other.pdf',
        ]);
        $other->forceFill(['is_default' => false])->save();

        app(DestroyTaxProfileAction::class)($other);

        $this->assertTrue($other->fresh()->trashed());
        $this->assertFalse($other->fresh()->is_default);
        $this->assertTrue($default->fresh()->is_default);
        $this->assertTrue(Storage::exists('fiscal-certificates/other.pdf'));
        $this->assertTrue(Storage::exists('fiscal-certificates/keep-default.pdf'));
    }

    #[Test]
    public function deactivate_default_reasigna_al_activo_mas_reciente(): void
    {
        [, $oldDefault, $customer] = $this->makeCustomerWithProfile('reassign@test.local');
        $oldDefault->forceFill([
            'is_default' => true,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->save();

        $mid = $customer->taxProfiles()->create([
            'name' => 'Mid',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/mid.pdf',
        ]);
        $mid->forceFill([
            'is_default' => false,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $newest = $customer->taxProfiles()->create([
            'name' => 'Newest',
            'rfc' => 'CACX7605101P8',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/new.pdf',
        ]);
        $newest->forceFill([
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        app(DestroyTaxProfileAction::class)($oldDefault);

        $this->assertTrue($oldDefault->fresh()->trashed());
        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertTrue($newest->fresh()->is_default);
        $this->assertFalse($mid->fresh()->is_default);
    }

    #[Test]
    public function deactivate_ultimo_activo_deja_sin_predeterminado(): void
    {
        [, $only] = $this->makeCustomerWithProfile('last@test.local');
        $only->forceFill(['is_default' => true])->save();

        app(DestroyTaxProfileAction::class)($only);

        $this->assertTrue($only->fresh()->trashed());
        $this->assertFalse($only->fresh()->is_default);
        $this->assertSame(
            0,
            TaxProfile::withTrashed()->where('customer_id', $only->customer_id)->where('is_default', true)->count()
        );
    }

    #[Test]
    public function ensure_repara_multiples_defaults_activos(): void
    {
        [, $a, $customer] = $this->makeCustomerWithProfile('multi@test.local');
        $a->forceFill([
            'is_default' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $b = $customer->taxProfiles()->create([
            'name' => 'B',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b.pdf',
        ]);
        $b->forceFill([
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $kept = app(EnsureActiveDefaultTaxProfileAction::class)->forCustomerId($customer->id);

        $this->assertSame($b->id, $kept->id);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertFalse($a->fresh()->is_default);
    }

    #[Test]
    public function http_set_default_y_destroy_autorizados_para_propietario(): void
    {
        [$user, $a, $customer] = $this->makeCustomerWithProfile('http@test.local');
        $a->forceFill(['is_default' => true])->save();
        $b = $customer->taxProfiles()->create([
            'name' => 'B',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/b.pdf',
        ]);

        $this->actingAs($user)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => $b->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->assertTrue($b->fresh()->is_default);
        $this->assertFalse($a->fresh()->is_default);

        $this->actingAs($user)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $a->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->assertTrue($a->fresh()->trashed());
        $this->assertTrue($b->fresh()->is_default);
    }

    #[Test]
    public function http_set_default_ajeno_responde_403(): void
    {
        [, $profile] = $this->makeCustomerWithProfile('owner-http@test.local');
        [$intruder] = $this->makeCustomerWithProfile('intruder-http@test.local');

        $this->actingAs($intruder)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => $profile->id]))
            ->assertForbidden();
    }

    #[Test]
    public function create_ignora_is_default_en_fillable_y_usa_force_interno(): void
    {
        $this->assertNotContains('is_default', (new TaxProfile)->getFillable());

        [$user] = $this->makeCustomer('fill@test.local');
        Auth::login($user);

        $profile = app(CreateTaxProfileAction::class)(
            name: 'Fill',
            rfc: 'MEBE931209BI2',
            zipcode: '64000',
            taxRegime: '612',
            cfdiUse: 'G03',
            fiscalCertificate: UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        );

        $this->assertTrue($profile->is_default);
        $profile->fill(['is_default' => false]);
        $this->assertTrue($profile->is_default);
    }

    #[Test]
    public function fallo_de_bd_elimina_constancia_nueva_y_no_toca_previa_ni_defaults(): void
    {
        [$user, $existing, $customer] = $this->makeCustomerWithProfile('orphan@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/previa.pdf',
        ]);
        $existing->forceFill(['is_default' => true])->save();
        Storage::put('fiscal-certificates/previa.pdf', 'previa-bytes');

        Auth::login($user);

        $this->app->instance(
            EnsureActiveDefaultTaxProfileAction::class,
            new class extends EnsureActiveDefaultTaxProfileAction
            {
                public function __invoke(Customer $customer, ?TaxProfile $preferredActive = null): ?TaxProfile
                {
                    throw new \RuntimeException('forced-db-failure');
                }
            }
        );

        $beforeIds = TaxProfile::query()->where('customer_id', $customer->id)->pluck('id')->all();

        try {
            app(CreateTaxProfileAction::class)(
                name: 'Nuevo',
                rfc: 'XAXX010101000',
                zipcode: '64000',
                taxRegime: '612',
                cfdiUse: 'G03',
                fiscalCertificate: UploadedFile::fake()->create('nueva.pdf', 100, 'application/pdf'),
            );
            $this->fail('Se esperaba excepción');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced-db-failure', $e->getMessage());
        }

        $this->assertTrue(Storage::exists('fiscal-certificates/previa.pdf'));
        $this->assertSame(['fiscal-certificates/previa.pdf'], Storage::files('fiscal-certificates'));
        $this->assertSame([], Storage::files('fiscal-certificates/test'));
        $this->assertEqualsCanonicalizing(
            $beforeIds,
            TaxProfile::query()->where('customer_id', $customer->id)->pluck('id')->all()
        );
        $this->assertTrue($existing->fresh()->is_default);
    }

    #[Test]
    public function http_requiere_autenticacion_para_set_default_y_destroy(): void
    {
        [, $profile] = $this->makeCustomerWithProfile('guest@test.local');

        $this->patch(route('tax-profiles.set-default', ['tax_profile' => $profile->id]))
            ->assertRedirect();

        $this->delete(route('tax-profiles.destroy', ['tax_profile' => $profile->id]))
            ->assertRedirect();

        $this->assertFalse($profile->fresh()->trashed());
    }

    #[Test]
    public function http_destroy_ajeno_responde_403_y_soft_deleted_set_default_404(): void
    {
        [$owner, $profile] = $this->makeCustomerWithProfile('own-del@test.local');
        $profile->forceFill(['is_default' => true])->save();
        [$intruder] = $this->makeCustomerWithProfile('intruder-del@test.local');

        $this->actingAs($intruder)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $profile->id]))
            ->assertForbidden();

        $this->assertFalse($profile->fresh()->trashed());

        $profile->delete();

        $this->actingAs($owner)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => $profile->id]))
            ->assertNotFound();
    }

    #[Test]
    public function http_perfil_inexistente_y_delete_repetido_no_alteran_default(): void
    {
        [$user, $default, $customer] = $this->makeCustomerWithProfile('repeat@test.local');
        $default->forceFill(['is_default' => true])->save();

        $other = $customer->taxProfiles()->create([
            'name' => 'Other',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/o.pdf',
        ]);

        $this->actingAs($user)
            ->patch(route('tax-profiles.set-default', ['tax_profile' => 999999]))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $other->id]))
            ->assertRedirect(route('tax-profiles.index'));

        $this->actingAs($user)
            ->delete(route('tax-profiles.destroy', ['tax_profile' => $other->id]))
            ->assertNotFound();

        $this->assertTrue($default->fresh()->is_default);
        $this->assertTrue($other->fresh()->trashed());
    }

    #[Test]
    public function destroy_no_toca_invoice_requests_ni_usa_force_delete_ni_is_used(): void
    {
        Schema::create('invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_profile_id')->nullable()->constrained('tax_profiles')->nullOnDelete();
            $table->string('invoice_requestable_type');
            $table->unsignedBigInteger('invoice_requestable_id');
            $table->string('name');
            $table->string('rfc');
            $table->string('zipcode');
            $table->string('tax_regime');
            $table->string('cfdi_use');
            $table->string('fiscal_certificate');
            $table->timestamps();
            $table->softDeletes();
        });

        [, $profile, $customer] = $this->makeCustomerWithProfile('snap@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/snap-keep.pdf',
        ]);
        $profile->forceFill(['is_default' => true])->save();
        Storage::put('fiscal-certificates/snap-keep.pdf', 'keep');

        $requestId = DB::table('invoice_requests')->insertGetId([
            'tax_profile_id' => $profile->id,
            'invoice_requestable_type' => 'App\\Models\\LaboratoryPurchase',
            'invoice_requestable_id' => 1,
            'name' => 'Snapshot',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/snap.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // isUsed existe pero Destroy no lo consulta como bloqueo.
        $this->assertTrue($profile->isUsed());
        app(DestroyTaxProfileAction::class)($profile);

        $this->assertTrue($profile->fresh()->trashed());
        $this->assertFalse($profile->fresh()->is_default);
        $this->assertNotNull(TaxProfile::withTrashed()->find($profile->id));
        $this->assertTrue(Storage::exists('fiscal-certificates/snap-keep.pdf'));

        $row = DB::table('invoice_requests')->where('id', $requestId)->first();
        $this->assertSame($profile->id, $row->tax_profile_id);
        $this->assertSame('Snapshot', $row->name);
        $this->assertSame('invoice-requests/snap.pdf', $row->fiscal_certificate);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('tax-profiles.restore'));
        $this->assertSame($customer->id, $profile->customer_id);

        Schema::dropIfExists('invoice_requests');
    }

    #[Test]
    public function ensure_cero_activos_cero_defaults_y_no_toca_otro_customer(): void
    {
        [, $a, $c1] = $this->makeCustomerWithProfile('e1@test.local');
        $a->forceFill(['is_default' => true])->save();
        [, $b] = $this->makeCustomerWithProfile('e2@test.local');
        $b->forceFill(['is_default' => true])->save();

        $a->delete();
        app(EnsureActiveDefaultTaxProfileAction::class)->forCustomerId($c1->id);

        $this->assertSame(
            0,
            TaxProfile::withTrashed()->where('customer_id', $c1->id)->where('is_default', true)->count()
        );
        $this->assertTrue($b->fresh()->is_default);
    }

    #[Test]
    public function excepcion_revierte_cambios_de_is_default(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithProfile('rb@test.local');
        $profile->forceFill(['is_default' => false])->save();

        try {
            DB::transaction(function () use ($customer, $profile) {
                app(EnsureActiveDefaultTaxProfileAction::class)($customer);
                $this->assertTrue($profile->fresh()->is_default);
                throw new \RuntimeException('rollback-please');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback-please', $e->getMessage());
        }

        $this->assertFalse($profile->fresh()->is_default);
    }

    #[Test]
    public function present_for_patient_expone_is_default_sin_internos(): void
    {
        [, $profile] = $this->makeCustomerWithProfile('props@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/secret.pdf',
        ]);
        $profile->forceFill(['is_default' => true])->save();

        $payload = $profile->fresh()->presentForPatient()->toArray();
        $this->assertTrue($payload['is_default']);
        $this->assertArrayNotHasKey('fiscal_certificate', $payload);
        $this->assertArrayNotHasKey('default_owner_key', $payload);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertArrayNotHasKey('deleted_at', $payload);
    }

    private function makeCustomer(string $email): array
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

        $user->setRelation('customer', $customer);

        return [$user, $customer];
    }

    private function makeCustomerWithProfile(string $email, array $overrides = []): array
    {
        [$user, $customer] = $this->makeCustomer($email);

        $profile = $customer->taxProfiles()->create(array_merge([
            'name' => 'Persona Fiscal',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/test-'.$user->id.'.pdf',
        ], $overrides));

        return [$user, $profile, $customer];
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['tax_profiles', 'customers', 'users'] as $table) {
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
            $table->string('codigo_postal_original')->nullable();
            $table->string('regimen_fiscal_original')->nullable();
            $table->string('tipo_persona')->nullable();
            $table->string('fecha_emision_constancia')->nullable();
            $table->string('estatus_sat')->nullable();
            $table->integer('tipo_persona_confianza')->default(0);
            $table->string('tipo_persona_detectado_por')->nullable();
            $table->string('hash_constancia')->nullable();
            $table->boolean('verificado_automaticamente')->default(false);
            $table->timestamp('fecha_verificacion')->nullable();
            $table->date('fecha_inscripcion')->nullable();
            $table->text('domicilio_fiscal')->nullable();
            $table->string('actividades_economicas')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['tax_profiles', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
