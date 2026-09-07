<?php

namespace Tests\Feature\Admin\LaboratoryBilling;

use App\Models\Administrator;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TaxProfile;
use App\Models\User;
use App\Jobs\LaboratoryBilling\GenerateLaboratoryBillingReportJob;
use App\Notifications\LaboratoryBillingAutomaticReportNotification;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingInvoicesQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingMetricsService;
use App\Services\LaboratoryBilling\LaboratoryBillingPresenter;
use App\Services\LaboratoryBilling\LaboratoryBillingRequestsQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingTaxProfilesQuery;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDataService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportScheduleCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            'famedic.laboratory_billing.invoice_delay_threshold_business_days' => 3,
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
        $reportsAdmin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $superAdmin = $this->makeSuperAdmin();
        $unauthorized = $this->makeAdmin([]);

        $this->assertTrue($access->allows($invoiceAdmin));
        $this->assertTrue($access->allows($manageAdmin));
        $this->assertTrue($access->allows($reportsAdmin));
        $this->assertTrue($access->allows($superAdmin));
        $this->assertFalse($access->allows($unauthorized));

        $this->assertFalse($access->allowsReports($invoiceAdmin));
        $this->assertTrue($access->allowsReports($manageAdmin));
        $this->assertTrue($access->allowsReports($reportsAdmin));
        $this->assertTrue($access->allowsReports($superAdmin));
        $this->assertFalse($access->allowsReports($unauthorized));
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
            'requested_at' => now()->subDays(6),
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
            'requested_at' => now()->subDays(6),
            'gda_order_id' => 'ACTIVE-LATE',
        ]);
        $cancelled = $this->seedRequest([
            'requested_at' => now()->subDays(6),
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
        $reportsAdmin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
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
                ->where('canManageAutomaticReports', false)
                ->has('adminNavigation'));

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

        $this->actingAs($reportsAdmin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LaboratoryBilling/AutomaticReports')
                ->has('schedules')
                ->has('runs')
                ->where('canManageAutomaticReports', true));

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertForbidden();

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
    public function automatic_reports_authorize_superadmin_manage_and_reports_permissions_only(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $manageAdmin = $this->makeAdmin(['laboratory-purchases.manage']);
        $reportsAdmin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $invoiceManager = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $unauthorized = $this->makeAdmin([]);

        $this->actingAs($superAdmin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk();

        $this->actingAs($manageAdmin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk();

        $this->actingAs($reportsAdmin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk();

        $this->actingAs($invoiceManager)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertForbidden();

        $this->actingAs($unauthorized)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertForbidden();
    }

    #[Test]
    public function invoice_manager_cannot_submit_automatic_report_actions_directly(): void
    {
        Queue::fake();

        $invoiceManager = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $schedule = $this->makeReportSchedule();
        $payload = [
            'name' => 'No debería crear',
            'is_active' => false,
            'weekdays' => [],
            'send_time' => '08:00',
            'timezone' => 'America/Monterrey',
            'period_type' => 'previous_day',
            'recipients' => 'billing@example.test',
            'included_sections' => ['activity'],
            'include_excel' => true,
        ];

        $this->actingAs($invoiceManager)
            ->post(route('admin.laboratory-billing.automatic-reports.store'), $payload)
            ->assertForbidden();

        $this->actingAs($invoiceManager)
            ->put(route('admin.laboratory-billing.automatic-reports.update', $schedule), $payload)
            ->assertForbidden();

        $this->actingAs($invoiceManager)
            ->post(route('admin.laboratory-billing.automatic-reports.run', $schedule), [
                'period_type' => 'previous_day',
            ])
            ->assertForbidden();

        $this->actingAs($invoiceManager)
            ->post(route('admin.laboratory-billing.automatic-reports.test', $schedule), [
                'period_type' => 'previous_day',
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function automatic_report_index_exposes_summary_cards_without_loading_full_collections(): void
    {
        $admin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $active = $this->makeReportSchedule(['name' => 'Activo', 'is_active' => true]);
        $paused = $this->makeReportSchedule(['name' => 'Pausado', 'is_active' => false]);
        $this->makeReportRun($active, LaboratoryBillingReportRun::TYPE_SCHEDULED, [
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $this->makeReportRun($paused, LaboratoryBillingReportRun::TYPE_SCHEDULED, [
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total', 2)
                ->where('summary.active', 1)
                ->where('summary.recentRuns', 1));
    }

    #[Test]
    public function automatic_report_preview_is_read_only_authorized_and_uses_report_data_service_metrics(): void
    {
        Notification::fake();
        Queue::fake();

        $admin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $unauthorized = $this->makeAdmin([]);
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-09 09:00:00', 'America/Monterrey'),
            'brand' => 'olab',
            'rfc' => 'PREV900101AA',
        ]);
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-09 09:00:00', 'America/Monterrey'),
            'brand' => 'swisslab',
            'rfc' => 'OTRA900101AA',
        ]);
        $schedule = $this->makeReportSchedule([
            'period_type' => LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_DAY,
            'filters' => ['brand' => 'olab'],
            'next_run_at' => Carbon::parse('2026-08-11 08:00:00', 'America/Monterrey'),
            'include_excel' => true,
        ]);
        $originalNextRun = $schedule->next_run_at?->toDateTimeString();
        $runsBefore = LaboratoryBillingReportRun::query()->count();

        $this->actingAs($unauthorized)
            ->getJson(route('admin.laboratory-billing.automatic-reports.preview', $schedule))
            ->assertForbidden();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.laboratory-billing.automatic-reports.preview', [
                'schedule' => $schedule->id,
                'test' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('is_test', true)
            ->assertJsonPath('excel.download_url', null)
            ->assertJsonPath('schedule.name', 'Reporte facturación');

        $period = app(LaboratoryBillingReportPeriodResolver::class)->resolve(
            LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_DAY,
            now(LaboratoryBillingReportPeriodResolver::TIMEZONE)
        );
        $expected = app(LaboratoryBillingReportDataService::class)->build(
            $period,
            ['brand' => 'olab'],
            now(LaboratoryBillingReportPeriodResolver::TIMEZONE)
        );

        $this->assertSame($expected['metrics']['received'], $response->json('metrics.received'));
        $this->assertSame($expected['metrics']['pending_backlog'], $response->json('metrics.pending_backlog'));
        $this->assertSame($runsBefore, LaboratoryBillingReportRun::query()->count());
        $this->assertSame($originalNextRun, $schedule->fresh()->next_run_at?->toDateTimeString());
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function automatic_report_form_rejects_invalid_email_normalizes_recipients_and_exposes_no_store_filter(): void
    {
        $admin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);

        $this->actingAs($admin)
            ->get(route('admin.laboratory-billing.automatic-reports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('options.stores.0.value', '__none__')
                ->where('options.stores.0.label', 'Sin sucursal'));

        $payload = [
            'name' => 'PRUEBA LOCAL - Reporte de facturación',
            'is_active' => true,
            'weekdays' => [1, 2, 3, 4, 5],
            'send_time' => '23:59',
            'timezone' => 'America/Monterrey',
            'period_type' => 'last_7_days',
            'recipients' => 'correo-invalido',
            'included_sections' => ['activity', 'backlog', 'overdue', 'completed', 'aging', 'missing_files'],
            'include_excel' => true,
            'brand' => '',
            'laboratory_store_id' => '',
            'status' => '',
        ];

        $this->actingAs($admin)
            ->from(route('admin.laboratory-billing.automatic-reports.index'))
            ->post(route('admin.laboratory-billing.automatic-reports.store'), $payload)
            ->assertSessionHasErrors('recipients.0');

        $payload['recipients'] = "local-billing-report@example.test\nLOCAL-BILLING-REPORT@example.test";

        $this->actingAs($admin)
            ->post(route('admin.laboratory-billing.automatic-reports.store'), $payload)
            ->assertRedirect(route('admin.laboratory-billing.automatic-reports.index'));

        $schedule = LaboratoryBillingReportSchedule::query()
            ->where('name', 'PRUEBA LOCAL - Reporte de facturación')
            ->firstOrFail();

        $this->assertSame(['local-billing-report@example.test'], $schedule->recipients);
        $this->assertTrue($schedule->is_active);
        $this->assertSame([1, 2, 3, 4, 5], $schedule->weekdays);
        $this->assertSame('last_7_days', $schedule->period_type);
        $this->assertSame('23:59', substr($schedule->send_time, 0, 5));
        $this->assertSame('2026-08-10 23:59:00', $schedule->next_run_at?->timezone('America/Monterrey')->toDateTimeString());
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
    public function automatic_report_separates_period_activity_from_current_backlog(): void
    {
        $oldPending = $this->seedRequest([
            'requested_at' => Carbon::parse('2026-07-20 09:00:00', 'America/Monterrey'),
            'gda_order_id' => 'OLD-PENDING',
            'rfc' => 'OLDP900101AAA',
        ]);
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-07-25 09:00:00', 'America/Monterrey'),
            'with_complete_invoice' => true,
            'invoice_completed_at' => Carbon::parse('2026-08-05 11:00:00', 'America/Monterrey'),
            'gda_order_id' => 'DONE-IN-PERIOD',
            'rfc' => 'DONE900101AAA',
        ]);
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'gda_order_id' => 'RECEIVED-IN-PERIOD',
            'rfc' => 'RECV900101AAA',
        ]);

        $period = [
            'start' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey'),
            'end' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey'),
            'start_utc' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey')->utc(),
            'end_utc' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey')->utc(),
            'label' => '1 ago 2026 - 10 ago 2026',
            'timezone' => 'America/Monterrey',
        ];

        $report = app(LaboratoryBillingReportDataService::class)->build($period, [], now('America/Monterrey'));

        $this->assertSame(1, $report['metrics']['received']);
        $this->assertSame(1, $report['metrics']['completed']);
        $this->assertGreaterThanOrEqual(2, $report['metrics']['pending_backlog']);
        $this->assertTrue($report['rows']['backlog']->pluck('id')->contains($oldPending['request']->id));
    }

    #[Test]
    public function automatic_report_schedule_calculates_next_run_and_paused_has_no_next_run(): void
    {
        $calculator = app(LaboratoryBillingReportScheduleCalculator::class);
        $active = LaboratoryBillingReportSchedule::query()->create([
            'name' => 'Diario facturación',
            'is_active' => true,
            'weekdays' => [1],
            'send_time' => '12:05',
            'timezone' => 'America/Monterrey',
            'period_type' => 'previous_day',
            'recipients' => ['admin@example.test'],
            'included_sections' => ['activity', 'backlog'],
            'include_excel' => true,
        ]);
        $paused = $active->replicate();
        $paused->fill(['name' => 'Pausado', 'is_active' => false])->save();

        $next = $calculator->nextRunAt($active, Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey'));

        $this->assertSame('2026-08-10 12:05:00', $next?->toDateTimeString());
        $this->assertNull($calculator->nextRunAt($paused, now()));
    }

    #[Test]
    public function dispatcher_creates_one_scheduled_run_for_due_configuration(): void
    {
        Queue::fake();

        $schedule = LaboratoryBillingReportSchedule::query()->create([
            'name' => 'Vencido',
            'is_active' => true,
            'weekdays' => [(int) now('America/Monterrey')->isoWeekday()],
            'send_time' => now('America/Monterrey')->format('H:i'),
            'timezone' => 'America/Monterrey',
            'period_type' => 'previous_day',
            'recipients' => ['admin@example.test'],
            'included_sections' => ['activity', 'backlog'],
            'include_excel' => true,
            'next_run_at' => Carbon::parse('2026-08-09 12:00:00', 'UTC'),
        ]);

        $this->artisan('laboratory-billing:dispatch-reports')->assertExitCode(0);
        $this->artisan('laboratory-billing:dispatch-reports')->assertExitCode(0);

        $this->assertSame(1, LaboratoryBillingReportRun::query()->where('schedule_id', $schedule->id)->count());
        Queue::assertPushed(GenerateLaboratoryBillingReportJob::class, 1);
    }

    #[Test]
    public function report_job_generates_excel_and_sends_notification_without_real_mail(): void
    {
        Notification::fake();
        Storage::fake('local');
        config(['famedic.laboratory_billing.report_disk' => 'local']);

        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'gda_order_id' => 'JOB-1',
            'rfc' => 'JOBR900101AAA',
        ]);

        $schedule = LaboratoryBillingReportSchedule::query()->create([
            'name' => 'Job facturación',
            'is_active' => true,
            'weekdays' => [1],
            'send_time' => '08:00',
            'timezone' => 'America/Monterrey',
            'period_type' => 'previous_day',
            'recipients' => ['billing@example.test'],
            'included_sections' => ['activity', 'backlog', 'overdue', 'completed'],
            'include_excel' => true,
        ]);
        $run = LaboratoryBillingReportRun::query()->create([
            'schedule_id' => $schedule->id,
            'run_type' => LaboratoryBillingReportRun::TYPE_TEST,
            'idempotency_key' => 'test-job-'.uniqid(),
            'status' => LaboratoryBillingReportRun::STATUS_PENDING,
            'intended_for_at' => now(),
            'recipients' => ['billing@example.test'],
            'filters' => [],
        ]);

        app(GenerateLaboratoryBillingReportJob::class, ['runId' => $run->id])->handle(
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver::class),
            app(LaboratoryBillingReportDataService::class),
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService::class),
        );

        $run->refresh();

        $this->assertSame(LaboratoryBillingReportRun::STATUS_SENT, $run->status);
        $this->assertNotNull($run->file_path);
        Storage::disk('local')->assertExists($run->file_path);
        Notification::assertSentOnDemand(LaboratoryBillingAutomaticReportNotification::class);
    }

    #[Test]
    public function automatic_report_handles_legacy_complete_invoices_filters_stores_and_truncation(): void
    {
        config(['famedic.laboratory_billing.report_detail_row_limit' => 1]);

        $store = LaboratoryStore::query()->create([
            'name' => 'Sucursal Centro',
            'brand' => 'olab',
            'state' => 'NL',
        ]);

        $legacyComplete = $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-04 09:00:00', 'America/Monterrey'),
            'with_store' => true,
            'laboratory_store_id' => $store->id,
            'brand' => 'olab',
            'rfc' => 'LEGACY901AAA',
        ]);
        $legacyComplete['purchase']->invoice()->create([
            'invoice' => 'invoices/legacy.pdf',
            'invoice_xml' => 'invoices/legacy.xml',
            'completed_at' => null,
            'created_at' => Carbon::parse('2026-08-05 10:00:00', 'America/Monterrey'),
            'updated_at' => Carbon::parse('2026-08-05 10:00:00', 'America/Monterrey'),
        ]);

        for ($i = 1; $i <= 101; $i++) {
            $this->seedRequest([
                'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
                'with_store' => true,
                'laboratory_store_id' => $store->id,
                'brand' => 'olab',
                'rfc' => 'PEND'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            ]);
        }
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-07 09:00:00', 'America/Monterrey'),
            'brand' => 'swisslab',
            'rfc' => 'OTHER901AAA',
        ]);

        $period = [
            'start' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey'),
            'end' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey'),
            'start_utc' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey')->utc(),
            'end_utc' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey')->utc(),
            'label' => '1 ago 2026 - 10 ago 2026',
            'timezone' => 'America/Monterrey',
        ];

        $report = app(LaboratoryBillingReportDataService::class)->build($period, [
            'brand' => 'olab',
            'laboratory_store_id' => $store->id,
        ], now('America/Monterrey'));

        $this->assertSame(102, $report['metrics']['received']);
        $this->assertSame(0, $report['metrics']['completed']);
        $this->assertSame(101, $report['metrics']['pending_backlog']);
        $this->assertTrue($report['metrics']['detail_truncated']);
        $this->assertSame(203, $report['metrics']['detail_total_rows']);
        $this->assertSame(200, $report['metrics']['detail_exported_rows']);
        $this->assertSame('Sucursal Centro', data_get($report['rows']['received']->first(), 'purchase.store.name'));

        $this->assertFalse($report['rows']['backlog']->pluck('id')->contains($legacyComplete['request']->id));
    }

    #[Test]
    public function automatic_report_labels_missing_store_without_blank_excel_cells(): void
    {
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'rfc' => 'NOSTORE01AAA',
        ]);

        $period = [
            'start' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey'),
            'end' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey'),
            'start_utc' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey')->utc(),
            'end_utc' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey')->utc(),
            'label' => '1 ago 2026 - 10 ago 2026',
            'timezone' => 'America/Monterrey',
        ];

        $report = app(LaboratoryBillingReportDataService::class)->build($period, [], now('America/Monterrey'));

        $this->assertSame('Sin sucursal', data_get($report['rows']['backlog']->first(), 'purchase.store.name'));
    }

    #[Test]
    public function automatic_report_can_filter_requests_without_store(): void
    {
        $withoutStore = $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'rfc' => 'NOSTORE02AA',
        ]);
        $store = LaboratoryStore::query()->create([
            'name' => 'Sucursal filtro',
            'brand' => 'olab',
            'state' => 'NL',
        ]);
        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'with_store' => true,
            'laboratory_store_id' => $store->id,
            'rfc' => 'WITHSTOREAA',
        ]);

        $period = [
            'start' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey'),
            'end' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey'),
            'start_utc' => Carbon::parse('2026-08-01 00:00:00', 'America/Monterrey')->utc(),
            'end_utc' => Carbon::parse('2026-08-10 23:59:59', 'America/Monterrey')->utc(),
            'label' => '1 ago 2026 - 10 ago 2026',
            'timezone' => 'America/Monterrey',
        ];

        $report = app(LaboratoryBillingReportDataService::class)->build($period, [
            'laboratory_store_id' => '__none__',
        ], now('America/Monterrey'));

        $this->assertSame(1, $report['metrics']['received']);
        $this->assertTrue($report['rows']['received']->pluck('id')->contains($withoutStore['request']->id));
        $this->assertSame('Sin sucursal', data_get($report['rows']['received']->first(), 'purchase.store.name'));
    }

    #[Test]
    public function report_job_uses_link_for_large_excel_and_exports_expected_sheets(): void
    {
        Notification::fake();
        Storage::fake('local');
        config([
            'famedic.laboratory_billing.report_disk' => 'local',
            'famedic.laboratory_billing.report_max_attachment_bytes' => 1,
        ]);

        $this->seedRequest([
            'requested_at' => Carbon::parse('2026-08-06 09:00:00', 'America/Monterrey'),
            'rfc' => 'LINK900101AA',
        ]);

        $schedule = $this->makeReportSchedule([
            'include_excel' => true,
            'included_sections' => ['activity', 'completed', 'backlog', 'overdue'],
        ]);
        $run = $this->makeReportRun($schedule, LaboratoryBillingReportRun::TYPE_TEST);

        app(GenerateLaboratoryBillingReportJob::class, ['runId' => $run->id])->handle(
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver::class),
            app(LaboratoryBillingReportDataService::class),
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService::class),
        );

        $run->refresh();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('local')->path($run->file_path));

        $this->assertSame('link', $run->delivery_method);
        $this->assertNotNull($run->link_expires_at);
        $this->assertSame([
            'Resumen',
            'Pendientes actuales',
            'Solicitudes atrasadas',
            'Completadas en periodo',
            'Actividad del periodo',
        ], $spreadsheet->getSheetNames());
        Notification::assertSentOnDemand(LaboratoryBillingAutomaticReportNotification::class);
    }

    #[Test]
    public function sent_report_job_is_not_delivered_again(): void
    {
        Notification::fake();

        $schedule = $this->makeReportSchedule();
        $run = $this->makeReportRun($schedule, LaboratoryBillingReportRun::TYPE_SCHEDULED, [
            'status' => LaboratoryBillingReportRun::STATUS_SENT,
            'sent_at' => now(),
        ]);

        app(GenerateLaboratoryBillingReportJob::class, ['runId' => $run->id])->handle(
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver::class),
            app(LaboratoryBillingReportDataService::class),
            app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService::class),
        );

        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_report_job_records_sanitized_error_without_sending_mail(): void
    {
        Notification::fake();
        config(['famedic.laboratory_billing.report_disk' => 'missing-report-disk']);

        $schedule = $this->makeReportSchedule(['include_excel' => true]);
        $run = $this->makeReportRun($schedule, LaboratoryBillingReportRun::TYPE_SCHEDULED);

        try {
            app(GenerateLaboratoryBillingReportJob::class, ['runId' => $run->id])->handle(
                app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver::class),
                app(LaboratoryBillingReportDataService::class),
                app(\App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService::class),
            );
            $this->fail('The report job should fail with an invalid disk.');
        } catch (\Throwable) {
            $run->refresh();
        }

        $this->assertSame(LaboratoryBillingReportRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotEmpty($run->error_message);
        $this->assertStringNotContainsString("\n", $run->error_message);
        Notification::assertNothingSent();
    }

    #[Test]
    public function manual_and_test_runs_do_not_change_the_scheduled_next_run(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $nextRunAt = Carbon::parse('2026-08-11 14:00:00', 'UTC');
        $schedule = $this->makeReportSchedule([
            'is_active' => true,
            'weekdays' => [2],
            'send_time' => '08:00',
            'next_run_at' => $nextRunAt,
        ]);
        $originalNextRunAt = $schedule->next_run_at?->utc()->toDateTimeString();

        $this->actingAs($admin)
            ->post(route('admin.laboratory-billing.automatic-reports.run', $schedule), [
                'period_type' => 'previous_day',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.laboratory-billing.automatic-reports.test', $schedule), [
                'period_type' => 'previous_day',
            ])
            ->assertRedirect();

        $schedule->refresh();

        $this->assertSame($originalNextRunAt, $schedule->next_run_at?->utc()->toDateTimeString());
        $this->assertSame(1, LaboratoryBillingReportRun::query()->where('run_type', LaboratoryBillingReportRun::TYPE_MANUAL)->count());
        $this->assertSame(1, LaboratoryBillingReportRun::query()->where('run_type', LaboratoryBillingReportRun::TYPE_TEST)->count());
        Queue::assertPushed(GenerateLaboratoryBillingReportJob::class, 2);
    }

    #[Test]
    public function paused_schedule_is_not_dispatched(): void
    {
        Queue::fake();

        $schedule = $this->makeReportSchedule([
            'is_active' => false,
            'next_run_at' => Carbon::parse('2026-08-09 12:00:00', 'UTC'),
        ]);

        $this->artisan('laboratory-billing:dispatch-reports')->assertExitCode(0);

        $this->assertSame(0, LaboratoryBillingReportRun::query()->where('schedule_id', $schedule->id)->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function report_download_requires_permission_signature_live_link_and_existing_file(): void
    {
        Storage::fake('local');

        $authorized = $this->makeAdmin(['laboratory-purchases.manage.billing-reports']);
        $invoiceManager = $this->makeAdmin(['laboratory-purchases.manage.invoices']);
        $unauthorized = $this->makeAdmin([]);
        $schedule = $this->makeReportSchedule();
        $run = $this->makeReportRun($schedule, LaboratoryBillingReportRun::TYPE_SCHEDULED, [
            'file_disk' => 'local',
            'file_path' => 'laboratory-billing/reports/secure/report.xlsx',
            'link_expires_at' => now()->addHour(),
        ]);
        Storage::disk('local')->put($run->file_path, 'xlsx-bytes');

        $url = URL::temporarySignedRoute(
            'admin.laboratory-billing.automatic-runs.download',
            now()->addHour(),
            ['run' => $run->id]
        );

        $this->actingAs($unauthorized)->get($url)->assertForbidden();
        $this->actingAs($invoiceManager)->get($url)->assertForbidden();
        $this->actingAs($authorized)->get($url.'&tampered=1')->assertForbidden();
        $this->actingAs($authorized)->get($url)->assertOk();

        $expiredSignedUrl = URL::temporarySignedRoute(
            'admin.laboratory-billing.automatic-runs.download',
            now()->subMinute(),
            ['run' => $run->id]
        );
        $this->actingAs($authorized)->get($expiredSignedUrl)->assertForbidden();

        $run->update(['link_expires_at' => now()->addHour(), 'file_path' => 'laboratory-billing/reports/secure/missing.xlsx']);
        $url = URL::temporarySignedRoute(
            'admin.laboratory-billing.automatic-runs.download',
            now()->addHour(),
            ['run' => $run->id]
        );
        $this->actingAs($authorized)->get($url)->assertNotFound();
    }

    #[Test]
    public function prune_report_files_deletes_expired_excel_and_keeps_history(): void
    {
        Storage::fake('local');
        config(['famedic.laboratory_billing.report_file_retention_days' => 14]);

        $schedule = $this->makeReportSchedule();
        $run = $this->makeReportRun($schedule, LaboratoryBillingReportRun::TYPE_SCHEDULED, [
            'file_disk' => 'local',
            'file_path' => 'laboratory-billing/reports/old/report.xlsx',
            'file_size' => 120,
            'link_expires_at' => now()->addHour(),
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);
        Storage::disk('local')->put($run->file_path, 'xlsx-bytes');

        $this->artisan('laboratory-billing:prune-report-files')->assertExitCode(0);

        Storage::disk('local')->assertMissing('laboratory-billing/reports/old/report.xlsx');
        $run->refresh();
        $this->assertNull($run->file_path);
        $this->assertNull($run->file_size);
        $this->assertNull($run->link_expires_at);
        $this->assertSame(LaboratoryBillingReportRun::STATUS_PENDING, $run->status);
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

    private function makeReportSchedule(array $overrides = []): LaboratoryBillingReportSchedule
    {
        return LaboratoryBillingReportSchedule::query()->create(array_merge([
            'name' => 'Reporte facturación',
            'is_active' => true,
            'weekdays' => [1],
            'send_time' => '08:00',
            'timezone' => 'America/Monterrey',
            'period_type' => 'previous_day',
            'filters' => [],
            'recipients' => ['billing@example.test'],
            'included_sections' => ['activity', 'completed', 'backlog', 'overdue', 'aging', 'missing_files'],
            'include_excel' => true,
        ], $overrides));
    }

    private function makeReportRun(
        LaboratoryBillingReportSchedule $schedule,
        string $type,
        array $overrides = [],
    ): LaboratoryBillingReportRun {
        return LaboratoryBillingReportRun::query()->create(array_merge([
            'schedule_id' => $schedule->id,
            'run_type' => $type,
            'idempotency_key' => 'laboratory-billing-report:test:'.$schedule->id.':'.uniqid('', true),
            'status' => LaboratoryBillingReportRun::STATUS_PENDING,
            'intended_for_at' => now(),
            'recipients' => $schedule->recipients,
            'filters' => [],
        ], $overrides));
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

    private function makeSuperAdmin(): User
    {
        $user = $this->makeAdmin([]);
        $role = Role::query()->firstOrCreate([
            'name' => 'superadmin',
            'guard_name' => 'web',
        ]);

        $user->administrator->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
            'brand' => $overrides['brand'] ?? 'olab',
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

        if (! empty($overrides['with_store'])) {
            $store = filled($overrides['laboratory_store_id'] ?? null)
                ? LaboratoryStore::query()->findOrFail($overrides['laboratory_store_id'])
                : LaboratoryStore::query()->create([
                    'name' => $overrides['store_name'] ?? 'Sucursal prueba',
                    'brand' => $overrides['brand'] ?? 'olab',
                    'state' => $overrides['store_state'] ?? 'NL',
                ]);

            LaboratoryAppointment::query()->create([
                'customer_id' => $customer->id,
                'laboratory_purchase_id' => $purchase->id,
                'laboratory_store_id' => $store->id,
                'brand' => $overrides['brand'] ?? 'olab',
                'created_at' => $overrides['requested_at'] ?? now(),
                'updated_at' => $overrides['requested_at'] ?? now(),
            ]);
        }

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
            'laboratory_billing_report_runs',
            'laboratory_billing_report_schedules',
            'laboratory_appointments',
            'laboratory_stores',
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

        Schema::create('laboratory_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand')->default('olab');
            $table->string('state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->foreignId('laboratory_store_id')->nullable();
            $table->string('brand')->default('olab');
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

        Schema::create('laboratory_billing_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->json('weekdays')->nullable();
            $table->time('send_time')->nullable();
            $table->string('timezone')->default('America/Monterrey');
            $table->string('period_type')->default('previous_day');
            $table->json('filters')->nullable();
            $table->json('included_sections')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('include_excel')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_billing_report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable();
            $table->string('run_type');
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('pending');
            $table->timestamp('intended_for_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('backlog_as_of')->nullable();
            $table->json('recipients')->nullable();
            $table->json('filters')->nullable();
            $table->json('metrics')->nullable();
            $table->string('delivery_method')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('link_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

        foreach ([
            'administrators.manage',
            'laboratory-purchases.manage',
            'laboratory-purchases.manage.invoices',
            'laboratory-purchases.manage.billing-reports',
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
            'laboratory_billing_report_runs',
            'laboratory_billing_report_schedules',
            'laboratory_appointments',
            'laboratory_stores',
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
