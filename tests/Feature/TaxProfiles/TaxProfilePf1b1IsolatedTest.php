<?php

namespace Tests\Feature\TaxProfiles;

use App\Console\Commands\AuditInvoiceRequestTaxProfileLinksCommand;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\TaxProfiles\InvoiceRequestTaxProfileLinker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite portable SQLite PF-1B.1.
 * La unicidad MySQL de default_owner_key NO se afirma aquí.
 */
class TaxProfilePf1b1IsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->bootstrapSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function is_default_existe_con_default_false_y_no_es_fillable(): void
    {
        $this->assertTrue(Schema::hasColumn('tax_profiles', 'is_default'));
        $this->assertNotContains('is_default', (new TaxProfile)->getFillable());
        $this->assertNotContains('deleted_at', (new TaxProfile)->getFillable());
        $this->assertNotContains('default_owner_key', (new TaxProfile)->getFillable());

        [, $profile] = $this->makeCustomerWithTaxProfile();
        $profile = $profile->fresh();
        $this->assertFalse($profile->is_default);
        $this->assertIsBool($profile->is_default);

        $profile->fill(['is_default' => true, 'deleted_at' => now(), 'default_owner_key' => 99]);
        $this->assertFalse($profile->is_default);
        $this->assertFalse($profile->isDirty('is_default'));
        $this->assertFalse($profile->isDirty('deleted_at'));
        $this->assertNull($profile->deleted_at);
        $this->assertArrayNotHasKey('default_owner_key', $profile->getAttributes());
    }

    #[Test]
    public function tax_profile_id_existe_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('invoice_requests', 'tax_profile_id'));

        [, , $customer] = $this->makeCustomerWithTaxProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'name' => 'X',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/a.pdf',
        ]);

        $this->assertNull($request->fresh()->tax_profile_id);
    }

    #[Test]
    public function relacion_con_perfil_activo_e_inversa(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/a.pdf',
        ]);

        $this->assertSame($profile->id, $request->fresh()->taxProfile->id);
        $this->assertTrue($profile->invoiceRequests()->whereKey($request->id)->exists());
        $this->assertTrue($profile->isUsed());
    }

    #[Test]
    public function relacion_con_perfil_soft_deleted_via_with_trashed(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/a.pdf',
        ]);

        $profile->delete();

        $this->assertTrue($profile->fresh()->trashed());
        $resolved = $request->fresh()->taxProfile;
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->trashed());
        $this->assertTrue($profile->invoiceRequests()->withTrashed()->exists());
        $this->assertTrue($profile->isUsed());
    }

    #[Test]
    public function force_delete_deja_fk_null_y_conserva_snapshot(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile();
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => 'Snapshot Name',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/snap.pdf',
        ]);

        DB::table('tax_profiles')->where('id', $profile->id)->delete();

        $fresh = $request->fresh();
        $this->assertNull($fresh->tax_profile_id);
        $this->assertSame('Snapshot Name', $fresh->name);
        $this->assertSame('MEBE931209BI2', $fresh->rfc);
        $this->assertSame('64000', $fresh->zipcode);
        $this->assertSame('612', $fresh->tax_regime);
        $this->assertSame('G03', $fresh->cfdi_use);
        $this->assertSame('invoice-requests/snap.pdf', $fresh->fiscal_certificate);
    }

    #[Test]
    public function backfill_customer_sin_activos_sin_default(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile();
        $profile->forceFill(['is_default' => true])->save();
        $profile->delete();

        $this->runDefaultBackfill();

        $this->assertSame(
            0,
            TaxProfile::withTrashed()->where('customer_id', $customer->id)->where('is_default', true)->count()
        );
    }

    #[Test]
    public function backfill_un_activo_queda_default(): void
    {
        [, $profile] = $this->makeCustomerWithTaxProfile();
        $profile->forceFill(['is_default' => false])->save();

        $this->runDefaultBackfill();

        $this->assertTrue($profile->fresh()->is_default);
    }

    #[Test]
    public function backfill_varios_activos_elige_mas_reciente_por_created_at(): void
    {
        [, $older, $customer] = $this->makeCustomerWithTaxProfile();
        $older->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'is_default' => false,
        ])->save();

        $newer = $customer->taxProfiles()->create([
            'name' => 'Nuevo',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/newer.pdf',
        ]);
        $newer->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
            'is_default' => false,
        ])->save();

        $this->runDefaultBackfill();

        $this->assertFalse($older->fresh()->is_default);
        $this->assertTrue($newer->fresh()->is_default);
    }

    #[Test]
    public function backfill_empate_created_at_usa_id_desc(): void
    {
        [, $first, $customer] = $this->makeCustomerWithTaxProfile();
        $tiedAt = now()->subMinutes(5);

        $first->forceFill([
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
            'is_default' => false,
        ])->save();

        $second = $customer->taxProfiles()->create([
            'name' => 'Segundo',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/second.pdf',
        ]);
        $second->forceFill([
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
            'is_default' => false,
        ])->save();

        $this->assertGreaterThan($first->id, $second->id);

        $this->runDefaultBackfill();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    #[Test]
    public function backfill_soft_deleted_no_participa_aunque_sea_mas_reciente(): void
    {
        [, $active, $customer] = $this->makeCustomerWithTaxProfile();
        $active->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'is_default' => false,
        ])->save();

        $trashed = $customer->taxProfiles()->create([
            'name' => 'Inactivo reciente',
            'rfc' => 'CACX7605101P8',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/trashed.pdf',
        ]);
        $trashed->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
            'is_default' => false,
        ])->save();
        $trashed->delete();

        $this->runDefaultBackfill();

        $this->assertTrue($active->fresh()->is_default);
        $this->assertFalse($trashed->fresh()->is_default);
    }

    #[Test]
    public function dos_customers_admiten_un_default_cada_uno_y_varios_no_default(): void
    {
        [, $a1, $c1] = $this->makeCustomerWithTaxProfile('c1@test.local');
        $a2 = $c1->taxProfiles()->create([
            'name' => 'No default',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/c1-b.pdf',
        ]);

        [, $b1, $c2] = $this->makeCustomerWithTaxProfile('c2@test.local');

        $this->runDefaultBackfill();

        $this->assertSame(1, TaxProfile::query()->where('customer_id', $c1->id)->where('is_default', true)->count());
        $this->assertSame(1, TaxProfile::query()->where('customer_id', $c2->id)->where('is_default', true)->count());
        $this->assertGreaterThanOrEqual(1, TaxProfile::query()->where('customer_id', $c1->id)->where('is_default', false)->count());
        $this->assertTrue($a1->fresh()->is_default || $a2->fresh()->is_default);
        $this->assertTrue($b1->fresh()->is_default);
    }

    #[Test]
    public function present_for_patient_expone_is_default_sin_internos_ni_paths(): void
    {
        [, $profile] = $this->makeCustomerWithTaxProfile();
        $profile->forceFill([
            'is_default' => true,
            'fiscal_certificate' => 'fiscal-certificates/secret.pdf',
        ])->save();

        $payload = $profile->fresh()->presentForPatient()->toArray();
        $json = json_encode($payload);

        $this->assertTrue($payload['is_default']);
        $this->assertArrayNotHasKey('fiscal_certificate', $payload);
        $this->assertArrayNotHasKey('default_owner_key', $payload);
        $this->assertArrayNotHasKey('hash_constancia', $payload);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertStringNotContainsString('fiscal-certificates/', $json);
    }

    #[Test]
    public function linker_unique_ambiguous_none_unresolved_soft_deleted_other_owner(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile('link@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/abc-unique.pdf',
        ]);
        $linker = new InvoiceRequestTaxProfileLinker;

        $uniquePurchase = $this->makeLaboratoryPurchase($customer, 'u1');
        $uniqueRequest = $uniquePurchase->invoiceRequest()->create([
            'name' => $profile->name,
            'rfc' => ' '.$profile->rfc.' ',
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/abc-unique.pdf',
        ]);

        $dup = $customer->taxProfiles()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/other.pdf',
        ]);

        $ambiguousPurchase = $this->makeLaboratoryPurchase($customer, 'a1');
        $ambiguousRequest = $ambiguousPurchase->invoiceRequest()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/no-basename-match.pdf',
        ]);

        $nonePurchase = $this->makeLaboratoryPurchase($customer, 'n1');
        $noneRequest = $nonePurchase->invoiceRequest()->create([
            'name' => 'Otro',
            'rfc' => 'XAXX010101000',
            'zipcode' => '99999',
            'tax_regime' => '601',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/none.pdf',
        ]);

        // Soft-deleted profile still unique by basename
        $softPurchase = $this->makeLaboratoryPurchase($customer, 's1');
        $softOnly = $customer->taxProfiles()->create([
            'name' => 'Soft Only',
            'rfc' => 'SOFT010101AAA',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/soft-only.pdf',
        ]);
        $softRequest = $softPurchase->invoiceRequest()->create([
            'name' => 'Soft Only',
            'rfc' => 'SOFT010101AAA',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/soft-only.pdf',
        ]);
        $softOnly->delete();

        [, $otherProfile] = $this->makeCustomerWithTaxProfile('other-owner@test.local', [
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'fiscal_certificate' => 'fiscal-certificates/abc-unique.pdf',
        ]);

        $unique = $linker->classify($uniqueRequest);
        $this->assertSame(InvoiceRequestTaxProfileLinker::CLASS_UNIQUE, $unique['classification']);
        $this->assertSame($profile->id, $unique['matched_tax_profile_id']);
        $this->assertNotSame($otherProfile->id, $unique['matched_tax_profile_id']);

        $ambiguous = $linker->classify($ambiguousRequest);
        $this->assertSame(InvoiceRequestTaxProfileLinker::CLASS_AMBIGUOUS, $ambiguous['classification']);
        $this->assertContains($profile->id, $ambiguous['candidate_ids']);
        $this->assertContains($dup->id, $ambiguous['candidate_ids']);

        $this->assertSame(
            InvoiceRequestTaxProfileLinker::CLASS_NONE,
            $linker->classify($noneRequest)['classification']
        );

        $unresolvedPurchaseGone = InvoiceRequest::query()->create([
            'name' => 'Orphan',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/orphan.pdf',
            'invoice_requestable_type' => LaboratoryPurchase::class,
            'invoice_requestable_id' => 888888,
        ]);
        $this->assertSame(
            InvoiceRequestTaxProfileLinker::CLASS_UNRESOLVED_OWNER,
            $linker->classify($unresolvedPurchaseGone)['classification']
        );

        $softClass = $linker->classify($softRequest);
        $this->assertSame(InvoiceRequestTaxProfileLinker::CLASS_UNIQUE, $softClass['classification']);
        $this->assertSame($softOnly->id, $softClass['matched_tax_profile_id']);
    }

    #[Test]
    public function apply_unique_idempotente_no_toca_ambiguous_ni_snapshot_ni_ya_vinculadas(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile('apply@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/apply-me.pdf',
        ]);
        $linker = new InvoiceRequestTaxProfileLinker;

        $uniquePurchase = $this->makeLaboratoryPurchase($customer, 'ap1');
        $uniqueRequest = $uniquePurchase->invoiceRequest()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/apply-me.pdf',
        ]);

        $customer->taxProfiles()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'fiscal-certificates/dup.pdf',
        ]);
        $ambiguousPurchase = $this->makeLaboratoryPurchase($customer, 'ap2');
        $ambiguousRequest = $ambiguousPurchase->invoiceRequest()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/ambiguous.pdf',
        ]);

        $nonePurchase = $this->makeLaboratoryPurchase($customer, 'ap3');
        $noneRequest = $nonePurchase->invoiceRequest()->create([
            'name' => 'Ninguno',
            'rfc' => 'NONE010101000',
            'zipcode' => '11111',
            'tax_regime' => '601',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/none.pdf',
        ]);

        $linkedPurchase = $this->makeLaboratoryPurchase($customer, 'ap4');
        $linkedRequest = $linkedPurchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'D01',
            'fiscal_certificate' => 'invoice-requests/already.pdf',
        ]);

        $first = $linker->applyUniqueMatches();
        $this->assertSame(1, $first);
        $this->assertSame($profile->id, $uniqueRequest->fresh()->tax_profile_id);
        $this->assertNull($ambiguousRequest->fresh()->tax_profile_id);
        $this->assertNull($noneRequest->fresh()->tax_profile_id);
        $this->assertSame('D01', $linkedRequest->fresh()->cfdi_use);
        $this->assertSame('invoice-requests/already.pdf', $linkedRequest->fresh()->fiscal_certificate);

        $uniqueSnap = $uniqueRequest->fresh();
        $second = $linker->applyUniqueMatches();
        $this->assertSame(0, $second);
        $this->assertSame($uniqueSnap->name, $uniqueRequest->fresh()->name);
        $this->assertSame($uniqueSnap->rfc, $uniqueRequest->fresh()->rfc);
        $this->assertSame($uniqueSnap->fiscal_certificate, $uniqueRequest->fresh()->fiscal_certificate);
        $this->assertSame(
            InvoiceRequestTaxProfileLinker::CLASS_ALREADY_LINKED,
            $linker->classify($uniqueRequest->fresh())['classification']
        );
    }

    #[Test]
    public function audit_command_dry_run_sin_escritura_ni_datos_fiscales(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile('cmd@test.local', [
            'fiscal_certificate' => 'fiscal-certificates/cmd.pdf',
        ]);
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/cmd.pdf',
        ]);

        $exit = Artisan::call(AuditInvoiceRequestTaxProfileLinksCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertNull($request->fresh()->tax_profile_id);
        $this->assertStringContainsString('auditoría', mb_strtolower($output));
        $this->assertStringNotContainsString('--apply', $output);
        $this->assertStringNotContainsString($profile->rfc, $output);
        $this->assertStringNotContainsString($profile->name, $output);
        $this->assertStringNotContainsString('fiscal-certificates/', $output);
        $this->assertStringNotContainsString('invoice-requests/cmd.pdf', $output);
    }

    #[Test]
    public function rollback_portable_de_columnas_sin_borrar_filas(): void
    {
        [, $profile, $customer] = $this->makeCustomerWithTaxProfile('rb@test.local');
        $purchase = $this->makeLaboratoryPurchase($customer);
        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => 'Keep',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'invoice-requests/keep.pdf',
        ]);

        Schema::table('invoice_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_profile_id');
        });
        $this->assertFalse(Schema::hasColumn('invoice_requests', 'tax_profile_id'));

        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
        $this->assertFalse(Schema::hasColumn('tax_profiles', 'is_default'));

        $this->assertDatabaseHas('tax_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('invoice_requests', [
            'id' => $request->id,
            'name' => 'Keep',
            'fiscal_certificate' => 'invoice-requests/keep.pdf',
        ]);
    }

    /**
     * Réplica del algoritmo de migración (portable). No afirma UNIQUE MySQL.
     */
    private function runDefaultBackfill(): void
    {
        DB::table('tax_profiles')->update(['is_default' => false]);

        DB::table('tax_profiles')
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $profileId = DB::table('tax_profiles')
                        ->where('customer_id', $row->customer_id)
                        ->whereNull('deleted_at')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    if ($profileId === null) {
                        continue;
                    }

                    DB::table('tax_profiles')
                        ->where('id', $profileId)
                        ->whereNull('deleted_at')
                        ->update(['is_default' => true]);
                }
            });
    }

    private function makeCustomerWithTaxProfile(string $email = 'owner@test.local', array $profileOverrides = []): array
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

    private function makeLaboratoryPurchase(Customer $customer, ?string $gdaOrderId = null): LaboratoryPurchase
    {
        return LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'gda_order_id' => $gdaOrderId ?? (string) random_int(100000, 999999),
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
