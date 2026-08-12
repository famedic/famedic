<?php

namespace Tests\Feature\Admin\LaboratoryBilling;

use App\Models\Administrator;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingInvoicesQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingMetricsService;
use App\Services\LaboratoryBilling\LaboratoryBillingPresenter;
use App\Services\LaboratoryBilling\LaboratoryBillingRequestsQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingTaxProfilesQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Esquema aislado: evita migraciones históricas incompatibles con SQLite/MySQL seeders.
 */
class LaboratoryBillingModuleIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        config([
            'famedic.laboratory_billing.invoice_delay_threshold_days' => 3,
            'permission.teams' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey'));

        $this->bootstrapSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'password.confirm',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function access_allows_invoice_and_manage_permissions(): void
    {
        $access = app(LaboratoryBillingAccess::class);

        $invoiceAdmin = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $manageAdmin = $this->makeAdmin(['laboratory-purchases.manage']);
        $unauthorized = $this->makeAdmin([]);

        $this->assertTrue($access->allows($invoiceAdmin));
        $this->assertTrue($access->allows($manageAdmin));
        $this->assertFalse($access->allows($unauthorized));
    }

    #[Test]
    public function metrics_count_pending_completed_overdue_and_in_progress(): void
    {
        $this->seedRequest(['requested_at' => now()->subDay(), 'gda_order_id' => 'PEND-1']);
        $this->seedRequest([
            'requested_at' => now()->subDay(),
            'with_complete_invoice' => true,
            'gda_order_id' => 'COMP-1',
            'rfc' => 'COMP900101AAA',
        ]);
        $this->seedRequest([
            'requested_at' => now()->subDays(5),
            'gda_order_id' => 'LATE-1',
            'rfc' => 'LATE900101AAA',
        ]);
        $this->seedRequest([
            'requested_at' => now()->subDay(),
            'with_pdf_only' => true,
            'gda_order_id' => 'PROG-1',
            'rfc' => 'PROG900101AAA',
        ]);

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $counts = app(LaboratoryBillingMetricsService::class)->requestCounts($range);
        $compliance = app(LaboratoryBillingMetricsService::class)->compliance($range);

        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(1, $counts['in_progress']);
        $this->assertSame(4, $compliance['received']);
        $this->assertSame(1, $compliance['completed']);
        $this->assertSame(25.0, $compliance['percent']);
    }

    #[Test]
    public function cancelled_purchases_are_excluded_from_billing_queries_metrics_and_exports(): void
    {
        $active = $this->seedRequest([
            'requested_at' => now()->subDays(5),
            'gda_order_id' => 'ACTIVE-LATE',
        ]);
        $cancelled = $this->seedRequest([
            'requested_at' => now()->subDays(5),
            'gda_order_id' => 'CANCELLED-LATE',
            'with_complete_invoice' => true,
        ]);
        $cancelled['purchase']->delete();

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $requests = app(LaboratoryBillingRequestsQuery::class);
        $metrics = app(LaboratoryBillingMetricsService::class);

        $this->assertSame([$active['request']->id], $requests->filteredQuery([], $range)->pluck('id')->all());
        $this->assertSame(1, $requests->paginate([], $range)->total());
        $this->assertSame(1, $requests->statusCounts([], $range)['all']);
        $this->assertSame(1, $requests->statusCounts(['status' => 'overdue'], $range)['overdue']);
        $this->assertSame(1, $requests->exportRows([], $range)->count());

        $counts = $metrics->requestCounts($range);
        $this->assertSame(1, $counts['total']);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(0, $counts['completed']);
        $this->assertSame(1, $metrics->compliance($range)['received']);
        $this->assertSame(0, collect($metrics->requestsVsInvoicesSeries($range)['points'])->sum('invoices_completed'));

        $this->assertCount(0, app(LaboratoryBillingInvoicesQuery::class)->exportRows([], $range));

        $cancelledProfile = app(LaboratoryBillingTaxProfilesQuery::class)->findForShow($cancelled['taxProfile']);
        $this->assertSame(0, $cancelledProfile['invoice_requests_count']);
        $this->assertSame([], $cancelledProfile['recent_requests']);
        $this->assertSame([], $cancelledProfile['monthly_usage']);
    }

    #[Test]
    public function requests_query_searches_by_folio_and_rfc_and_filters_documents(): void
    {
        $this->seedRequest([
            'patient_name' => 'María',
            'gda_order_id' => 'FOLIO-777',
            'rfc' => 'BUSC900101AAA',
            'requested_at' => now()->subDay(),
        ]);
        $this->seedRequest([
            'patient_name' => 'Otro',
            'gda_order_id' => 'OTRO-1',
            'rfc' => 'OTRO900101AAA',
            'requested_at' => now()->subDay(),
            'with_complete_invoice' => true,
        ]);

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $query = app(LaboratoryBillingRequestsQuery::class);

        $byFolio = $query->filteredQuery(['search' => 'FOLIO-777'], $range)->get();
        $this->assertCount(1, $byFolio);
        $this->assertSame('BUSC900101AAA', $byFolio->first()->rfc);

        $byRfc = $query->filteredQuery(['search' => 'BUSC900101AAA'], $range)->get();
        $this->assertCount(1, $byRfc);

        $complete = $query->filteredQuery(['document' => 'complete'], $range)->get();
        $this->assertCount(1, $complete);
        $this->assertSame('OTRO900101AAA', $complete->first()->rfc);
    }

    #[Test]
    public function invoices_query_distinguishes_complete_and_missing_xml(): void
    {
        $this->seedRequest([
            'with_complete_invoice' => true,
            'requested_at' => now()->subDay(),
            'rfc' => 'COMP900101BBB',
        ]);
        $this->seedRequest([
            'with_pdf_only' => true,
            'requested_at' => now()->subDay(),
            'rfc' => 'PDFX900101BBB',
        ]);

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $query = app(LaboratoryBillingInvoicesQuery::class);

        $complete = $query->paginate(['document' => 'complete'], $range);
        $this->assertCount(1, $complete->items());
        $this->assertSame('complete', $complete->items()[0]['billing']['document_status']);

        $missingXml = $query->paginate(['document' => 'missing_xml'], $range);
        $this->assertCount(1, $missingXml->items());
        $this->assertSame('missing_xml', $missingXml->items()[0]['billing']['document_status']);
    }

    #[Test]
    public function tax_profiles_query_tracks_usage_soft_delete_and_hides_paths(): void
    {
        $seeded = $this->seedRequest([
            'requested_at' => now()->subDay(),
            'rfc' => 'PERF900101AAA',
        ]);

        $unused = $seeded['customer']->taxProfiles()->create([
            'name' => 'SIN USO',
            'razon_social' => 'SIN USO',
            'rfc' => 'SINU900101AAA',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'D01',
            'fiscal_certificate' => 'private/secret-path.pdf',
            'is_default' => false,
        ]);

        $deleted = $seeded['customer']->taxProfiles()->create([
            'name' => 'ELIMINADO',
            'razon_social' => 'ELIMINADO',
            'rfc' => 'ELIM900101AAA',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'D01',
            'is_default' => false,
        ]);
        $deleted->delete();

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $query = app(LaboratoryBillingTaxProfilesQuery::class);
        $metrics = $query->metrics($range);

        $this->assertGreaterThanOrEqual(1, $metrics['unused']);

        $detail = $query->findForShow($unused);
        $this->assertSame('SINU900101AAA', $detail['rfc']);
        $this->assertArrayNotHasKey('fiscal_certificate', $detail);
        $this->assertTrue($detail['has_fiscal_certificate']);
        $this->assertNotNull($detail['fiscal_certificate_url']);
        $this->assertStringNotContainsString('private/secret-path.pdf', (string) json_encode($detail));

        $presented = app(LaboratoryBillingPresenter::class)->presentTaxProfile($unused);
        $this->assertArrayNotHasKey('fiscal_certificate', $presented);
    }

    #[Test]
    public function authorized_user_can_open_module_pages_and_unauthorized_gets_403(): void
    {
        $admin = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $unauthorized = $this->makeAdmin([]);
        $this->seedRequest(['requested_at' => now()->subDay()]);

        $response = $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.dashboard', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LaboratoryBilling/Dashboard')
                ->has('requestMetrics')
                ->where('thresholdDays', 3)
                ->has('adminNavigation'));

        $navigation = collect($response->original->getData()['page']['props']['adminNavigation'] ?? []);
        $labs = $navigation->firstWhere('label', 'Laboratorios');
        $this->assertNotNull($labs);
        $this->assertTrue(collect($labs['items'] ?? [])->pluck('label')->contains('Facturación'));

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.requests', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.invoices', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.tax-profiles.index', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.reports', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.export.requests', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]))
            ->assertOk();

        $this->actingAs($unauthorized)
            ->get(route('admin.laboratory-billing.dashboard'))
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->get(route('admin.laboratory-billing.export.requests'))
            ->assertForbidden();
    }

    #[Test]
    public function completed_invoice_stays_in_original_period_after_document_replace(): void
    {
        $completedAt = Carbon::parse('2026-08-05 10:00:00', 'America/Monterrey');

        $seeded = $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-03 09:00:00', 'America/Monterrey'),
            'with_complete_invoice' => true,
            'invoice_completed_at' => $completedAt,
            'invoice_created_at' => $completedAt,
            'invoice_updated_at' => $completedAt,
            'rfc' => 'ONCE900101AAA',
        ]);

        $invoice = $seeded['purchase']->invoice;
        $originalCompletedAt = $invoice->completed_at->copy();
        $responseHours = app(\App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver::class)
            ->responseTimeHours($seeded['request'], $invoice);

        // Simula reemplazo posterior (antes reiniciaba created_at).
        $invoice->update([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice->refresh();

        // completed_at no debe moverse por updates de timestamps.
        $this->assertTrue($originalCompletedAt->equalTo($invoice->completed_at));

        $range = LaboratoryBillingDateRange::fromInput('2026-08-01', '2026-08-10');
        $series = app(LaboratoryBillingMetricsService::class)->requestsVsInvoicesSeries($range);
        $avg = app(LaboratoryBillingMetricsService::class)->averageResponseTimeHours($range);

        $totalCompleted = collect($series['points'])->sum('invoices_completed');
        $this->assertSame(1, $totalCompleted);
        $this->assertSame($responseHours, $avg);

        $pointOnCompletedDay = collect($series['points'])->firstWhere(
            'key',
            $completedAt->timezone('America/Monterrey')->toDateString()
        );
        $this->assertNotNull($pointOnCompletedDay);
        $this->assertSame(1, $pointOnCompletedDay['invoices_completed']);
    }

    #[Test]
    public function invoice_permission_allows_upload_manage_and_superadmin_deny_others(): void
    {
        $seeded = $this->seedRequest(['requested_at' => now()->subDay()]);
        $purchase = $seeded['purchase'];

        $invoiceAdmin = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $manageAdmin = $this->makeAdmin(['laboratory-purchases.manage']);
        $unauthorized = $this->makeAdmin([]);

        $this->assertTrue($invoiceAdmin->can('uploadInvoice', $purchase));
        $this->assertTrue($invoiceAdmin->can('view', $purchase));
        $this->assertFalse($invoiceAdmin->can('update', $purchase));

        $this->assertTrue($manageAdmin->can('uploadInvoice', $purchase));
        $this->assertTrue($manageAdmin->can('update', $purchase));

        $this->assertFalse($unauthorized->can('uploadInvoice', $purchase));
        $this->assertFalse($unauthorized->can('view', $purchase));

        $super = $this->makeAdmin([]);
        $role = Role::query()->firstOrCreate([
            'name' => 'superadmin',
            'guard_name' => 'web',
        ]);
        $super->administrator->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $super = $super->fresh()->load('administrator');

        $this->assertTrue($super->can('uploadInvoice', $purchase));

        $purchase->delete();
        $cancelledPurchase = LaboratoryPurchase::withTrashed()->findOrFail($purchase->id);

        $this->assertFalse($invoiceAdmin->can('uploadInvoice', $cancelledPurchase));
        $this->assertFalse($manageAdmin->can('uploadInvoice', $cancelledPurchase));
        $this->assertFalse($super->can('uploadInvoice', $cancelledPurchase));
    }

    #[Test]
    public function invoice_admin_can_store_and_replace_invoice_documents_via_http(): void
    {
        Notification::fake();
        Storage::fake('local');

        $seeded = $this->seedRequest(['requested_at' => now()->subDay(), 'gda_order_id' => 'UPL-1']);
        $purchase = $seeded['purchase'];
        $invoiceAdmin = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $unauthorized = $this->makeAdmin([]);

        $this->actingAs($unauthorized)
            ->post(route('admin.laboratory-purchases.invoice', $purchase), [
                'invoice' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
                'invoice_xml' => UploadedFile::fake()->create('factura.xml', 20, 'application/xml'),
            ])
            ->assertForbidden();

        $this->actingAs($invoiceAdmin)
            ->post(route('admin.laboratory-purchases.invoice', $purchase), [
                'invoice' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
                'invoice_xml' => UploadedFile::fake()->create('factura.xml', 20, 'application/xml'),
            ])
            ->assertRedirect(route('admin.laboratory-purchases.show', $purchase));

        $invoice = $purchase->fresh()->invoice;
        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->completed_at);
        $createdAt = $invoice->created_at->copy();
        $completedAt = $invoice->completed_at->copy();

        Carbon::setTestNow(Carbon::parse('2026-08-10 15:00:00', 'America/Monterrey'));

        $this->actingAs($invoiceAdmin)
            ->post(route('admin.laboratory-purchases.invoice', $purchase), [
                'invoice' => UploadedFile::fake()->create('reemplazo.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.laboratory-purchases.show', $purchase));

        $invoice->refresh();
        $this->assertTrue($createdAt->equalTo($invoice->created_at));
        $this->assertTrue($completedAt->equalTo($invoice->completed_at));
        $this->assertTrue($invoice->updated_at->gt($completedAt));
        $this->assertNotNull($invoice->getRawOriginal('invoice_xml'));

        $this->actingAs($invoiceAdmin)
            ->post(route('admin.laboratory-purchases.invoice', $purchase), [
                'invoice_xml' => UploadedFile::fake()->create('reemplazo.xml', 20, 'application/xml'),
            ])
            ->assertRedirect(route('admin.laboratory-purchases.show', $purchase));

        $invoice->refresh();
        $this->assertTrue($createdAt->equalTo($invoice->created_at));
        $this->assertTrue($completedAt->equalTo($invoice->completed_at));
    }

    #[Test]
    public function export_invoices_uses_completed_at_not_created_at_as_completion(): void
    {
        $completedAt = Carbon::parse('2026-08-04 11:00:00', 'America/Monterrey');
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-02 09:00:00', 'America/Monterrey'),
            'with_complete_invoice' => true,
            'invoice_completed_at' => $completedAt,
            'invoice_created_at' => Carbon::parse('2026-08-09 18:00:00', 'America/Monterrey'),
            'invoice_updated_at' => Carbon::parse('2026-08-09 18:00:00', 'America/Monterrey'),
            'rfc' => 'EXPT900101AAA',
        ]);

        $admin = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $response = $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.export.invoices', [
                'from' => '2026-08-01',
                'to' => '2026-08-10',
            ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Fecha finalización', $csv);
        $this->assertStringContainsString('Última actualización', $csv);
        $this->assertStringNotContainsString('Fecha carga', $csv);
    }

    #[Test]
    public function historical_complete_backfill_uses_least_of_created_and_updated(): void
    {
        $purchase = $this->seedRequest(['requested_at' => now()->subDays(4)])['purchase'];

        $created = '2026-08-08 12:00:00';
        $updated = '2026-08-05 08:00:00';

        $invoiceId = \Illuminate\Support\Facades\DB::table('invoices')->insertGetId([
            'invoiceable_type' => LaboratoryPurchase::class,
            'invoiceable_id' => $purchase->id,
            'invoice' => 'invoices/hist.pdf',
            'invoice_xml' => 'invoices/hist.xml',
            'completed_at' => null,
            'created_at' => $created,
            'updated_at' => $updated,
        ]);

        // Misma estrategia documentada en la migración (SQLite).
        $row = \Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoiceId)->first();
        $completedAt = $row->created_at;
        if ($row->created_at && $row->updated_at && $row->updated_at < $row->created_at) {
            $completedAt = $row->updated_at;
        }
        \Illuminate\Support\Facades\DB::table('invoices')
            ->where('id', $invoiceId)
            ->update(['completed_at' => $completedAt]);

        $this->assertSame($updated, \Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoiceId)->value('completed_at'));
    }

    private function makeAdmin(array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid('', true).'@test.local',
            'password' => bcrypt('password'),
        ]);

        $administrator = Administrator::query()->create([
            'user_id' => $user->id,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $administrator->givePermissionTo($permission);
        }

        return $user->fresh()->load('administrator');
    }

    /**
     * @return array{user: User, customer: Customer, purchase: LaboratoryPurchase, request: InvoiceRequest, taxProfile: TaxProfile}
     */
    private function seedRequest(array $overrides = []): array
    {
        $user = User::query()->create([
            'name' => $overrides['customer_name'] ?? 'Ana',
            'paternal_lastname' => 'Billing',
            'email' => $overrides['email'] ?? ('ana-'.uniqid('', true).'@example.com'),
            'password' => bcrypt('password'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'customerable_type' => 'App\\Models\\RegularAccount',
            'customerable_id' => 1,
        ]);

        $taxProfile = $customer->taxProfiles()->create([
            'name' => $overrides['tax_name'] ?? 'ANA BILLING',
            'razon_social' => $overrides['tax_name'] ?? 'ANA BILLING',
            'rfc' => $overrides['rfc'] ?? ('RFC'.strtoupper(substr(uniqid(), -8))),
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'D01',
            'tipo_persona' => 'fisica',
            'is_default' => true,
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'brand' => 'olab',
            'gda_order_id' => $overrides['gda_order_id'] ?? ('ORD-'.random_int(1000, 9999)),
            'name' => $overrides['patient_name'] ?? 'Ana',
            'paternal_lastname' => 'Paciente',
            'maternal_lastname' => 'Lab',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 2,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => $overrides['total_cents'] ?? 150000,
            'customer_id' => $customer->id,
            'created_at' => $overrides['purchase_created_at'] ?? now(),
            'updated_at' => $overrides['purchase_created_at'] ?? now(),
        ]);

        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $taxProfile->id,
            'name' => $taxProfile->name,
            'rfc' => $taxProfile->rfc,
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'D01',
            'created_at' => $overrides['requested_at'] ?? now(),
            'updated_at' => $overrides['requested_at'] ?? now(),
        ]);

        if (! empty($overrides['with_complete_invoice'])) {
            $completedAt = $overrides['invoice_completed_at']
                ?? $overrides['invoice_created_at']
                ?? now();

            $purchase->invoice()->create([
                'invoice' => 'invoices/complete.pdf',
                'invoice_xml' => 'invoices/complete.xml',
                'completed_at' => $completedAt,
                'created_at' => $overrides['invoice_created_at'] ?? $completedAt,
                'updated_at' => $overrides['invoice_updated_at'] ?? $completedAt,
            ]);
        }

        if (! empty($overrides['with_pdf_only'])) {
            $purchase->invoice()->create([
                'invoice' => 'invoices/only.pdf',
                'invoice_xml' => null,
                'completed_at' => null,
                'created_at' => $overrides['invoice_created_at'] ?? now(),
                'updated_at' => $overrides['invoice_created_at'] ?? now(),
            ]);
        }

        return compact('user', 'customer', 'purchase', 'request', 'taxProfile');
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'invoices',
            'invoice_requests',
            'tax_profiles',
            'laboratory_purchases',
            'laboratory_concierges',
            'administrators',
            'customers',
            'users',
            'notifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('paternal_lastname')->nullable();
            $table->string('maternal_lastname')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('administrators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_concierges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrator_id')->constrained();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('customerable_type')->nullable();
            $table->unsignedBigInteger('customerable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('permission_id')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });

        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('name')->nullable();
            $table->string('razon_social')->nullable();
            $table->string('rfc')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('cfdi_use')->nullable();
            $table->string('fiscal_certificate')->nullable();
            $table->string('tipo_persona')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('domicilio_fiscal')->nullable();
            $table->string('estatus_sat')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->foreignId('tax_profile_id')->nullable();
            $table->morphs('invoice_requestable');
            $table->string('name')->nullable();
            $table->string('rfc')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('cfdi_use')->nullable();
            $table->string('fiscal_certificate')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('invoiceable');
            $table->string('invoice')->nullable();
            $table->string('invoice_xml')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::enableForeignKeyConstraints();

        foreach ([
            'administrators.manage',
            'laboratory-purchases.manage',
            'laboratory-purchases.manage.invoices',
            'laboratory-purchases.manage.vendor-payments',
            'laboratory-tests.manage',
            'online-pharmacy-purchases.manage',
            'online-pharmacy-purchases.manage.vendor-payments',
            'medical-attention-subscriptions.manage',
            'customers.manage',
            'coupons.manage',
            'documentation.manage',
            'simulators.manage',
            'logs-general.manage',
            'users.manage',
            'view carts',
            'efevoo-tokens.manage',
            'tax-profiles.manage',
            'payment-attempts.manage',
            'laboratory-notifications.monitor',
            'view_config_monitor',
            'monitoring-ai.manage',
            'cupones.view',
        ] as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'invoices',
            'invoice_requests',
            'tax_profiles',
            'laboratory_purchases',
            'laboratory_concierges',
            'administrators',
            'customers',
            'users',
            'notifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
