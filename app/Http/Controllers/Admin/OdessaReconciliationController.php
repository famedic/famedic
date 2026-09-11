<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OdessaReconciliationItem;
use App\Models\OdessaReconciliationRun;
use App\Services\Odessa\Reconciliation\OdessaReconciliationActionService;
use App\Services\Odessa\Reconciliation\OdessaReconciliationAdminPayload;
use App\Services\Odessa\Reconciliation\OdessaReconciliationRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OdessaReconciliationController extends Controller
{
    private const VIEW_PERMISSION = 'odessa-reconciliation.view';

    private const MANAGE_PERMISSION = 'odessa-reconciliation.manage';

    private const REVIEW_PERMISSION = 'odessa-reconciliation.review';

    public function index(Request $request, OdessaReconciliationAdminPayload $payload): Response
    {
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        $runs = OdessaReconciliationRun::query()
            ->with('uploadedBy')
            ->visible()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $latestRun = OdessaReconciliationRun::query()->visible()->latest()->first();

        return Inertia::render('Admin/Odessa/Reconciliations/Index', [
            'runs' => $payload->index($runs),
            'dashboard' => [
                'total_runs' => OdessaReconciliationRun::query()->visible()->count(),
                'latest_run' => $latestRun ? [
                    'created_at' => $latestRun->created_at?->toDateTimeString(),
                    'unique_collaborators' => $latestRun->unique_collaborators,
                    'confirmed_count' => $latestRun->confirmed_count,
                    'not_found_count' => $latestRun->not_found_count,
                    'show_url' => route('admin.odessa.reconciliations.show', $latestRun, absolute: false),
                ] : null,
                'pending_review' => OdessaReconciliationItem::query()
                    ->where('review_status', OdessaReconciliationItem::REVIEW_PENDING)
                    ->count(),
            ],
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);

        return Inertia::render('Admin/Odessa/Reconciliations/Create', [
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request, OdessaReconciliationRunService $service): RedirectResponse
    {
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);

        $request->validate([
            'source_file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls'],
            'murguia_file' => ['nullable', 'file', 'max:20480', 'mimes:xlsx,xls'],
        ], [
            'source_file.required' => 'Selecciona el reporte de colaboradores ODESSA.',
            'source_file.mimes' => 'El reporte de colaboradores debe ser un archivo .xlsx o .xls.',
            'murguia_file.mimes' => 'El reporte Murguía debe ser un archivo .xlsx o .xls.',
        ]);

        try {
            $run = $service->createRun(
                $request->file('source_file'),
                $request->file('murguia_file'),
                $request->user(),
            );
        } catch (\Throwable $exception) {
            Log::warning('ODESSA reconciliation persisted run failed', [
                'message' => $exception->getMessage(),
                'source_filename' => $request->file('source_file')?->getClientOriginalName(),
                'murguia_filename' => $request->file('murguia_file')?->getClientOriginalName(),
            ]);

            return back()
                ->withErrors(['source_file' => $this->friendlyError($exception)])
                ->withInput();
        }

        return redirect()
            ->route('admin.odessa.reconciliations.show', $run)
            ->with('success', 'Conciliación persistida y lista para revisión.');
    }

    public function show(
        Request $request,
        OdessaReconciliationRun $run,
        OdessaReconciliationAdminPayload $payload,
    ): Response {
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        return Inertia::render('Admin/Odessa/Reconciliations/Show', [
            'preview' => $payload->fromRun($run),
            'successMessage' => $request->session()->get('success'),
            'canReview' => $this->can($request, self::REVIEW_PERMISSION),
            'canActions' => collect(OdessaReconciliationActionService::permissions())
                ->mapWithKeys(fn (string $permission, string $action) => [$action => $this->can($request, $permission)])
                ->all(),
        ]);
    }

    public function export(Request $request, OdessaReconciliationRun $run): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        if (! $run->export_path || ! Storage::disk('local')->exists($run->export_path)) {
            return redirect()
                ->route('admin.odessa.reconciliations.show', $run)
                ->withErrors(['export' => 'No hay un XLSX histórico disponible para esta corrida.']);
        }

        return response()->download(
            Storage::disk('local')->path($run->export_path),
            'odessa-conciliacion-'.$run->uuid.'.xlsx',
        );
    }

    public function review(
        Request $request,
        OdessaReconciliationRun $run,
        OdessaReconciliationItem $item,
        OdessaReconciliationRunService $service,
    ): RedirectResponse {
        $this->authorizeAccess($request, self::REVIEW_PERMISSION);

        $validated = $request->validate([
            'review_status' => ['required', 'string', 'in:'.implode(',', OdessaReconciliationItem::reviewStatuses())],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->updateReview(
            $run,
            $item,
            $request->user(),
            $validated['review_status'],
            $validated['comment'] ?? null,
        );

        return redirect()
            ->route('admin.odessa.reconciliations.show', $run)
            ->with('success', 'Revisión actualizada.');
    }

    public function archive(
        Request $request,
        OdessaReconciliationRun $run,
        OdessaReconciliationRunService $service,
    ): RedirectResponse {
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);

        $service->archive($run, $request->user());

        return redirect()
            ->route('admin.odessa.reconciliations.index')
            ->with('success', 'Conciliación archivada.');
    }

    public function previewAction(
        Request $request,
        OdessaReconciliationRun $run,
        OdessaReconciliationItem $item,
        string $action,
        OdessaReconciliationActionService $service,
    ): JsonResponse {
        $this->authorizeAction($request, $action);

        return response()->json($service->preview($run, $item, $action));
    }

    public function executeAction(
        Request $request,
        OdessaReconciliationRun $run,
        OdessaReconciliationItem $item,
        string $action,
        OdessaReconciliationActionService $service,
    ): RedirectResponse {
        $this->authorizeAction($request, $action);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'string'],
            'preview_token' => ['required', 'string'],
        ]);

        $result = $service->execute(
            $run,
            $item,
            $request->user(),
            $action,
            $validated['reason'],
            $validated['confirmation'],
            $validated['preview_token'],
        );

        $redirect = redirect()->route('admin.odessa.reconciliations.show', $run);

        return ($result['ok'] ?? false)
            ? $redirect->with('success', $result['message'] ?? 'Acción correctiva ejecutada.')
            : $redirect->withErrors(['action' => ($result['code'] ?? 'ACTION_FAILED').': '.($result['message'] ?? 'No se pudo ejecutar la acción.')]);
    }

    private function authorizeAccess(Request $request, string $permission): void
    {
        $this->can($request, $permission) || abort(403);
    }

    private function authorizeAction(Request $request, string $action): void
    {
        $permission = OdessaReconciliationActionService::permissions()[$action] ?? null;

        if (! $permission) {
            abort(404);
        }

        $this->authorizeAccess($request, $permission);
    }

    private function can(Request $request, string $permission): bool
    {
        $administrator = $request->user()?->administrator;
        if (! $administrator) {
            return false;
        }

        try {
            if ($administrator->hasPermissionTo($permission)) {
                return true;
            }
        } catch (PermissionDoesNotExist) {
            //
        }

        return $administrator->roles()->where('roles.id', 1)->exists();
    }

    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException) {
            return $exception->getMessage();
        }

        if (str_contains($exception->getMessage(), 'Unable to create a directory')) {
            return 'No se pudo guardar el archivo de conciliación. Verifica permisos de storage/app/private e inténtalo de nuevo.';
        }

        return 'No se pudo analizar el archivo. Verifica que sea un Excel válido con encabezados de colaboradores ODESSA.';
    }
}
