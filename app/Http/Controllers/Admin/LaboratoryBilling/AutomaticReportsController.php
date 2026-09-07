<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Enums\LaboratoryBillingStatus;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\RunLaboratoryBillingReportRequest;
use App\Http\Requests\Admin\LaboratoryBilling\StoreLaboratoryBillingReportScheduleRequest;
use App\Jobs\LaboratoryBilling\GenerateLaboratoryBillingReportJob;
use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Models\LaboratoryStore;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDataService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportScheduleCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutomaticReportsController extends Controller
{
    public function index(Request $request, LaboratoryBillingAccess $access): Response
    {
        $access->authorizeReports($request->user());

        $schedules = LaboratoryBillingReportSchedule::query()
            ->with('latestRun')
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (LaboratoryBillingReportSchedule $schedule) => $this->presentSchedule($schedule));

        $runs = LaboratoryBillingReportRun::query()
            ->with('schedule:id,name')
            ->when($request->filled('run_status'), fn ($q) => $q->where('status', $request->input('run_status')))
            ->when($request->filled('run_type'), fn ($q) => $q->where('run_type', $request->input('run_type')))
            ->when($request->filled('run_from'), fn ($q) => $q->where('created_at', '>=', $request->date('run_from')?->startOfDay()))
            ->when($request->filled('run_to'), fn ($q) => $q->where('created_at', '<=', $request->date('run_to')?->endOfDay()))
            ->latest()
            ->paginate(12, ['*'], 'runs_page')
            ->withQueryString()
            ->through(fn (LaboratoryBillingReportRun $run) => $this->presentRun($run));

        return Inertia::render('Admin/LaboratoryBilling/AutomaticReports', [
            'summary' => [
                'total' => LaboratoryBillingReportSchedule::query()->count(),
                'active' => LaboratoryBillingReportSchedule::query()->where('is_active', true)->count(),
                'recentRuns' => LaboratoryBillingReportRun::query()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            'schedules' => $schedules,
            'runs' => $runs,
            'filters' => $request->only(['run_status', 'run_type', 'run_from', 'run_to', 'tab']),
            'options' => [
                'periods' => LaboratoryBillingReportSchedule::periodOptions(),
                'sections' => LaboratoryBillingReportSchedule::sectionOptions(),
                'weekdays' => LaboratoryBillingReportSchedule::weekdayOptions(),
                'brands' => collect(LaboratoryBrand::cases())->map(fn (LaboratoryBrand $brand) => [
                    'value' => $brand->value,
                    'label' => $brand->label(),
                ])->values(),
                'stores' => LaboratoryStore::query()
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->limit(500)
                    ->get(['id', 'name', 'brand', 'state'])
                    ->map(fn (LaboratoryStore $store) => [
                        'value' => $store->id,
                        'label' => trim($store->name.' · '.($store->state ?? '')),
                        'brand' => $store->brand?->value ?? $store->brand,
                    ])
                    ->prepend([
                        'value' => '__none__',
                        'label' => 'Sin sucursal',
                        'brand' => null,
                    ])
                    ->values(),
                'statuses' => collect(LaboratoryBillingStatus::cases())->map(fn (LaboratoryBillingStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->values(),
                'timezone' => 'America/Monterrey',
            ],
            'config' => [
                'maxAttachmentMb' => round(((int) config('famedic.laboratory_billing.report_max_attachment_bytes', 8 * 1024 * 1024)) / 1024 / 1024, 1),
                'linkTtlHours' => (int) config('famedic.laboratory_billing.report_link_ttl_hours', 72),
                'retentionDays' => (int) config('famedic.laboratory_billing.report_file_retention_days', 14),
            ],
            'canManageAutomaticReports' => true,
        ]);
    }

    public function preview(
        Request $request,
        LaboratoryBillingReportSchedule $schedule,
        LaboratoryBillingAccess $access,
        LaboratoryBillingReportPeriodResolver $periodResolver,
        LaboratoryBillingReportDataService $dataService,
    ): JsonResponse {
        $access->authorizeReports($request->user());

        $periodType = (string) $request->input('period_type', $schedule->period_type);
        $period = $periodResolver->resolve(
            $periodType,
            now(LaboratoryBillingReportPeriodResolver::TIMEZONE),
            $request->input('custom_from'),
            $request->input('custom_to'),
        );
        $reportData = $dataService->build($period, $schedule->filters ?? [], now(LaboratoryBillingReportPeriodResolver::TIMEZONE));
        $sections = $schedule->included_sections ?? [];
        $metrics = $reportData['metrics'] ?? [];
        $isTest = $request->boolean('test');

        return response()->json([
            'subject' => ($isTest ? '[PRUEBA] ' : '').'Reporte de facturación: '.$schedule->name,
            'is_test' => $isTest,
            'schedule' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'is_active' => $schedule->is_active,
                'recipients_count' => count($schedule->recipients ?? []),
                'include_excel' => $schedule->include_excel,
            ],
            'period' => [
                'label' => $period['label'],
                'timezone' => $period['timezone'],
            ],
            'generated_at' => localizedDate(now(LaboratoryBillingReportPeriodResolver::TIMEZONE))?->isoFormat('D MMM Y h:mm a'),
            'metrics' => [
                'received' => $metrics['received'] ?? 0,
                'completed' => $metrics['completed'] ?? 0,
                'pending_backlog' => $metrics['pending_backlog'] ?? 0,
                'overdue_backlog' => $metrics['overdue_backlog'] ?? 0,
                'compliance_percent' => $metrics['compliance_percent'] ?? 0,
                'average_response_hours' => $metrics['average_response_hours'] ?? null,
                'oldest_pending' => data_get($metrics, 'oldest_pending.billing.requested_at') ?? data_get($metrics, 'oldest_pending.formatted_requested_at'),
                'aging' => $metrics['aging'] ?? [],
                'missing_files' => $metrics['missing_files'] ?? [],
                'detail_truncated' => $metrics['detail_truncated'] ?? false,
                'detail_total_rows' => $metrics['detail_total_rows'] ?? 0,
                'detail_exported_rows' => $metrics['detail_exported_rows'] ?? 0,
            ],
            'included_sections' => $sections,
            'alerts' => [
                'overdue' => $metrics['overdue_backlog'] ?? 0,
                'detail_truncated' => (bool) ($metrics['detail_truncated'] ?? false),
            ],
            'excel' => [
                'enabled' => (bool) $schedule->include_excel,
                'filename' => 'reporte-facturacion-laboratorio.xlsx',
                'delivery_hint' => 'Se enviará como adjunto hasta '.round(((int) config('famedic.laboratory_billing.report_max_attachment_bytes', 8 * 1024 * 1024)) / 1024 / 1024, 1).' MB; si excede, mediante enlace temporal protegido.',
                'download_url' => null,
            ],
            'copy' => [
                'headline' => 'Resumen de facturación',
                'intro' => 'Este reporte combina la actividad del periodo con los pendientes actuales.',
                'closing' => 'Consulta el módulo de facturación para revisar el detalle operativo.',
            ],
        ]);
    }

    public function store(StoreLaboratoryBillingReportScheduleRequest $request, LaboratoryBillingReportScheduleCalculator $calculator): RedirectResponse
    {
        $schedule = LaboratoryBillingReportSchedule::query()->create([
            ...$this->payload($request),
            'created_by' => $request->user()->id,
        ]);
        $schedule->update(['next_run_at' => $calculator->nextRunAt($schedule)]);

        return redirect()->route('admin.laboratory-billing.automatic-reports.index')
            ->flashMessage('Reporte automático creado correctamente.');
    }

    public function update(
        StoreLaboratoryBillingReportScheduleRequest $request,
        LaboratoryBillingReportSchedule $schedule,
        LaboratoryBillingReportScheduleCalculator $calculator,
    ): RedirectResponse {
        $schedule->update($this->payload($request));
        $schedule->update(['next_run_at' => $calculator->nextRunAt($schedule)]);

        return redirect()->route('admin.laboratory-billing.automatic-reports.index')
            ->flashMessage('Reporte automático actualizado correctamente.');
    }

    public function run(
        RunLaboratoryBillingReportRequest $request,
        LaboratoryBillingReportSchedule $schedule,
    ): RedirectResponse {
        if (empty($schedule->recipients)) {
            return back()->withErrors(['recipients' => 'Agrega al menos un destinatario antes de ejecutar el reporte.']);
        }

        $run = $this->createManualRun($schedule, LaboratoryBillingReportRun::TYPE_MANUAL, $request);
        GenerateLaboratoryBillingReportJob::dispatch($run->id);

        return back()->flashMessage('Ejecución manual encolada correctamente.');
    }

    public function test(
        RunLaboratoryBillingReportRequest $request,
        LaboratoryBillingReportSchedule $schedule,
    ): RedirectResponse {
        if (empty($schedule->recipients)) {
            return back()->withErrors(['recipients' => 'Agrega al menos un destinatario antes de enviar una prueba.']);
        }

        $run = $this->createManualRun($schedule, LaboratoryBillingReportRun::TYPE_TEST, $request);
        GenerateLaboratoryBillingReportJob::dispatch($run->id);

        return back()->flashMessage('Envío de prueba encolado correctamente.');
    }

    public function download(Request $request, LaboratoryBillingReportRun $run, LaboratoryBillingAccess $access): StreamedResponse
    {
        $access->authorizeReports($request->user());
        $run->refresh();

        abort_unless($run->file_disk && $run->file_path, 404);
        abort_unless(! $run->link_expires_at || $run->link_expires_at->isFuture(), 403);
        abort_unless(Storage::disk($run->file_disk)->exists($run->file_path), 404);

        return Storage::disk($run->file_disk)->download($run->file_path, 'reporte-facturacion-laboratorio.xlsx');
    }

    private function payload(StoreLaboratoryBillingReportScheduleRequest $request): array
    {
        return [
            'name' => $request->validated('name'),
            'is_active' => $request->boolean('is_active'),
            'weekdays' => $request->validated('weekdays', []),
            'send_time' => $request->validated('send_time'),
            'timezone' => 'America/Monterrey',
            'period_type' => $request->validated('period_type'),
            'filters' => $request->validated('filters', []),
            'included_sections' => $request->validated('included_sections') ?: collect(LaboratoryBillingReportSchedule::sectionOptions())->pluck('value')->all(),
            'recipients' => $request->validated('recipients', []),
            'include_excel' => $request->boolean('include_excel'),
            'updated_by' => $request->user()->id,
        ];
    }

    private function createManualRun(
        LaboratoryBillingReportSchedule $schedule,
        string $type,
        RunLaboratoryBillingReportRequest $request,
    ): LaboratoryBillingReportRun {
        $filters = $schedule->filters ?? [];
        $filters['_period_type'] = $request->input('period_type', $schedule->period_type);

        if ($filters['_period_type'] === 'custom_range') {
            $filters['_custom_from'] = $request->input('custom_from');
            $filters['_custom_to'] = $request->input('custom_to');
        }

        return LaboratoryBillingReportRun::query()->create([
            'schedule_id' => $schedule->id,
            'run_type' => $type,
            'idempotency_key' => 'laboratory-billing-report:'.$type.':'.$schedule->id.':'.Str::uuid(),
            'status' => LaboratoryBillingReportRun::STATUS_PENDING,
            'intended_for_at' => now(),
            'recipients' => $schedule->recipients,
            'filters' => $filters,
        ]);
    }

    private function presentSchedule(LaboratoryBillingReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'is_active' => $schedule->is_active,
            'weekdays' => $schedule->weekdays ?? [],
            'send_time' => $schedule->send_time,
            'timezone' => $schedule->timezone,
            'period_type' => $schedule->period_type,
            'filters' => $schedule->filters ?? [],
            'included_sections' => $schedule->included_sections ?? [],
            'recipients' => $schedule->recipients ?? [],
            'include_excel' => $schedule->include_excel,
            'next_run_at' => $schedule->next_run_at?->timezone($schedule->timezone)->isoFormat('D MMM Y h:mm a'),
            'last_run_at' => $schedule->last_run_at?->timezone($schedule->timezone)->isoFormat('D MMM Y h:mm a'),
            'latest_run' => $schedule->latestRun ? $this->presentRun($schedule->latestRun) : null,
        ];
    }

    private function presentRun(LaboratoryBillingReportRun $run): array
    {
        return [
            'id' => $run->id,
            'schedule_name' => $run->schedule?->name,
            'run_type' => $run->run_type,
            'status' => $run->status,
            'period' => $run->period_start && $run->period_end
                ? localizedDate($run->period_start)?->isoFormat('D MMM Y').' - '.localizedDate($run->period_end)?->isoFormat('D MMM Y')
                : null,
            'recipients' => $run->recipients ?? [],
            'metrics' => $run->metrics ?? [],
            'delivery_method' => $run->delivery_method,
            'file_size' => $run->file_size,
            'link_expires_at' => $run->link_expires_at?->isoFormat('D MMM Y h:mm a'),
            'started_at' => $run->started_at?->isoFormat('D MMM Y h:mm a'),
            'sent_at' => $run->sent_at?->isoFormat('D MMM Y h:mm a'),
            'finished_at' => $run->finished_at?->isoFormat('D MMM Y h:mm a'),
            'error_message' => $run->error_message,
            'download_url' => $run->file_path && $run->link_expires_at?->isFuture()
                ? URL::temporarySignedRoute('admin.laboratory-billing.automatic-runs.download', $run->link_expires_at, ['run' => $run->id])
                : null,
        ];
    }
}
