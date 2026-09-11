<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Odessa\GeneratePreEnrollmentMedicalAttentionIdAction;
use App\Actions\Odessa\RegisterPreEnrollmentWithMurguiaAction;
use App\Actions\Odessa\RetryPreEnrollmentMurguiaAction;
use App\Actions\Odessa\VerifyPreEnrollmentMurguiaStatusAction;
use App\Exports\OdessaPreEnrollmentsExport;
use App\Http\Controllers\Controller;
use App\Models\OdessaPreEnrollment;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentMurguiaRegistrationPayload;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentImportService;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OdessaPreEnrollmentController extends Controller
{
    private const VIEW_PERMISSION = 'odessa-pre-enrollments.view';
    private const MANAGE_PERMISSION = 'odessa-pre-enrollments.manage';
    private const GENERATE_CREDIT_PERMISSION = 'odessa-pre-enrollments.actions.generate-credit';
    private const IMPORT_PERMISSION = 'odessa-pre-enrollments.actions.import';
    private const MURGUIA_REGISTER_PERMISSION = 'odessa-pre-enrollments.actions.murguia-register';
    private const MURGUIA_VERIFY_PERMISSION = 'odessa-pre-enrollments.actions.murguia-verify';
    private const MURGUIA_RETRY_PERMISSION = 'odessa-pre-enrollments.actions.murguia-retry';

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        $filters = $request->only(['search', 'source_action', 'status', 'link_status', 'murguia_status', 'credit', 'flag']);
        $canManage = $this->can($request, self::MANAGE_PERMISSION);
        $query = OdessaPreEnrollment::query()
            ->with(['linkedUser', 'linkedCustomer', 'linkedOdessaAccount'])
            ->filter($filters, $canManage);

        return Inertia::render('Admin/Odessa/PreEnrollments/Index', [
            'preEnrollments' => $query->latest()->paginate(25)->withQueryString()->through(fn (OdessaPreEnrollment $item) => $this->indexRow($item, $canManage)),
            'dashboard' => $this->dashboard(),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => OdessaPreEnrollment::statuses(),
                'link_statuses' => OdessaPreEnrollment::linkStatuses(),
                'murguia_statuses' => OdessaPreEnrollment::murguiaStatuses(),
                'source_actions' => OdessaPreEnrollment::sourceActions(),
            ],
            'canManage' => $canManage,
            'canGenerateCredit' => $this->can($request, self::GENERATE_CREDIT_PERMISSION),
            'generateCreditEnabled' => (bool) config('famedic.odessa_pre_enrollments.generate_credit_enabled', false),
            'canRegisterMurguia' => $this->canManageAction($request, self::MURGUIA_REGISTER_PERMISSION),
            'canVerifyMurguia' => $this->canManageAction($request, self::MURGUIA_VERIFY_PERMISSION),
            'canRetryMurguia' => $this->canManageAction($request, self::MURGUIA_RETRY_PERMISSION),
            'murguiaEnabled' => (bool) config('famedic.odessa_pre_enrollments.murguia_enabled', false),
            'murguiaRetryEnabled' => (bool) config('famedic.odessa_pre_enrollments.murguia_retry_enabled', false),
            'murguiaContractConfigured' => OdessaPreEnrollmentMurguiaRegistrationPayload::isConfigured(),
            'murguiaEndpointLabel' => $this->murguiaEndpointLabel($request),
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function show(Request $request, OdessaPreEnrollment $preEnrollment): Response
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        $preEnrollment->load(['linkedUser', 'linkedCustomer', 'linkedOdessaAccount', 'creator', 'audits.performer']);

        return Inertia::render('Admin/Odessa/PreEnrollments/Show', [
            'preEnrollment' => $this->detailRow($preEnrollment, $request),
            'canGenerateCredit' => $this->can($request, self::GENERATE_CREDIT_PERMISSION),
            'creditGenerationEnabled' => (bool) config('famedic.odessa_pre_enrollments.generate_credit_enabled', false),
            'canRegisterMurguia' => $this->canManageAction($request, self::MURGUIA_REGISTER_PERMISSION),
            'canVerifyMurguia' => $this->canManageAction($request, self::MURGUIA_VERIFY_PERMISSION),
            'canRetryMurguia' => $this->canManageAction($request, self::MURGUIA_RETRY_PERMISSION),
            'murguiaEnabled' => (bool) config('famedic.odessa_pre_enrollments.murguia_enabled', false),
            'murguiaRetryEnabled' => (bool) config('famedic.odessa_pre_enrollments.murguia_retry_enabled', false),
            'murguiaContractConfigured' => OdessaPreEnrollmentMurguiaRegistrationPayload::isConfigured(),
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function import(Request $request): Response
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);

        return Inertia::render('Admin/Odessa/PreEnrollments/Import', [
            'preview' => null,
            'canImport' => $this->can($request, self::IMPORT_PERMISSION),
            'importEnabled' => (bool) config('famedic.odessa_pre_enrollments.import_enabled', false),
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function previewImport(Request $request, OdessaPreEnrollmentPreviewService $service): Response|RedirectResponse
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);

        $request->validate([
            'source_file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls'],
        ]);

        try {
            $preview = $service->preview($request->file('source_file'), $request->user());
        } catch (\Throwable $exception) {
            return back()
                ->withErrors(['source_file' => $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'No se pudo analizar el Excel.'])
                ->withInput();
        }

        return Inertia::render('Admin/Odessa/PreEnrollments/Import', [
            'preview' => $preview,
            'canImport' => $this->can($request, self::IMPORT_PERMISSION),
            'importEnabled' => (bool) config('famedic.odessa_pre_enrollments.import_enabled', false),
        ]);
    }

    public function confirmImport(Request $request, OdessaPreEnrollmentImportService $service): RedirectResponse
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);
        $this->authorizeAccess($request, self::IMPORT_PERMISSION);

        if (! config('famedic.odessa_pre_enrollments.import_enabled', false)) {
            return back()->withErrors(['import' => 'La importación persistente de preafiliaciones está deshabilitada por configuración.']);
        }

        $validated = $request->validate([
            'run_uuid' => ['required', 'uuid'],
            'source_file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls'],
            'confirmation' => ['required', 'string', 'in:IMPORTAR'],
        ]);

        $result = $service->confirm(
            (string) $validated['run_uuid'],
            $request->file('source_file'),
            $request->user(),
        );

        if (! ($result['ok'] ?? false)) {
            return back()->withErrors(['import' => $result['message'] ?? 'No se pudo confirmar la importación.']);
        }

        return redirect()
            ->route('admin.odessa.pre-enrollments.index')
            ->with('success', sprintf(
                'Importación completada. Creados=%d, omitidos=%d.',
                (int) ($result['created'] ?? 0),
                (int) ($result['omitted'] ?? 0),
            ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::VIEW_PERMISSION);

        return Excel::download(
            new OdessaPreEnrollmentsExport($request->only(['search', 'source_action', 'status', 'link_status', 'murguia_status', 'credit', 'flag'])),
            'odessa-preafiliaciones-'.now()->format('Y-m-d_His').'.xlsx',
        );
    }

    public function generateCreditPreview(
        Request $request,
        OdessaPreEnrollment $preEnrollment,
        GeneratePreEnrollmentMedicalAttentionIdAction $action,
    ) {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::GENERATE_CREDIT_PERMISSION);

        return response()->json($action->preview($preEnrollment));
    }

    public function generateCredit(
        Request $request,
        OdessaPreEnrollment $preEnrollment,
        GeneratePreEnrollmentMedicalAttentionIdAction $action,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::GENERATE_CREDIT_PERMISSION);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'string', 'in:CONFIRMAR'],
        ]);

        $result = $action->execute($preEnrollment, $request->user(), $validated['reason']);

        $redirect = redirect()->route('admin.odessa.pre-enrollments.show', $preEnrollment);

        return ($result['ok'] ?? false)
            ? $redirect->with('success', $result['message'] ?? 'Identificador reservado.')
            : $redirect->withErrors(['generate_credit' => $result['message'] ?? 'No se pudo reservar el identificador.']);
    }

    public function registerMurguia(
        Request $request,
        OdessaPreEnrollment $preEnrollment,
        RegisterPreEnrollmentWithMurguiaAction $action,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);
        $this->authorizeAccess($request, self::MURGUIA_REGISTER_PERMISSION);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:REGISTRAR'],
        ]);

        $result = $action->execute($preEnrollment, $request->user());

        return $this->murguiaRedirect($preEnrollment, $result, 'murguia_register');
    }

    public function verifyMurguia(
        Request $request,
        OdessaPreEnrollment $preEnrollment,
        VerifyPreEnrollmentMurguiaStatusAction $action,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);
        $this->authorizeAccess($request, self::MURGUIA_VERIFY_PERMISSION);

        $result = $action->execute($preEnrollment, $request->user());

        return $this->murguiaRedirect($preEnrollment, $result, 'murguia_verify');
    }

    public function retryMurguia(
        Request $request,
        OdessaPreEnrollment $preEnrollment,
        RetryPreEnrollmentMurguiaAction $action,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeAccess($request, self::MANAGE_PERMISSION);
        $this->authorizeAccess($request, self::MURGUIA_RETRY_PERMISSION);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:REINTENTAR'],
        ]);

        $result = $action->execute($preEnrollment, $request->user());

        return $this->murguiaRedirect($preEnrollment, $result, 'murguia_retry');
    }

    private function dashboard(): array
    {
        $base = OdessaPreEnrollment::query();

        return [
            'total' => (clone $base)->count(),
            'altas' => (clone $base)->where('source_action', OdessaPreEnrollment::ACTION_ALTA)->count(),
            'historicos' => (clone $base)->where('source_action', OdessaPreEnrollment::ACTION_HISTORICO)->count(),
            'pending_account' => (clone $base)->where('link_status', OdessaPreEnrollment::LINK_PENDING_ACCOUNT)->count(),
            'ready' => (clone $base)->where('status', OdessaPreEnrollment::STATUS_READY)->count(),
            'linked' => (clone $base)->where('link_status', OdessaPreEnrollment::LINK_LINKED)->count(),
            'blocked' => (clone $base)->where('status', OdessaPreEnrollment::STATUS_BLOCKED)->count(),
            'with_credit' => (clone $base)->whereNotNull('medical_attention_identifier')->count(),
            'without_credit' => (clone $base)->whereNull('medical_attention_identifier')->count(),
            'murguia_active' => (clone $base)->where('murguia_status', OdessaPreEnrollment::MURGUIA_ACTIVE)->count(),
            'murguia_pending' => (clone $base)->where('murguia_status', OdessaPreEnrollment::MURGUIA_PENDING)->count(),
            'murguia_error' => (clone $base)->where('murguia_status', OdessaPreEnrollment::MURGUIA_FAILED)->count(),
            'possible_duplicates' => (clone $base)->where(function ($q) {
                $q->where('link_status', OdessaPreEnrollment::LINK_POSSIBLE_DUPLICATE)
                    ->orWhereJsonContains('data_quality_flags', 'POSSIBLE_DUPLICATE_PERSON');
            })->count(),
        ];
    }

    private function indexRow(OdessaPreEnrollment $item, bool $includeIdentity = false): array
    {
        $row = [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'source_row' => $item->source_row,
            'source_action' => $item->source_action,
            'murguia_status' => $item->murguia_status,
            'status' => $item->status,
            'link_status' => $item->link_status,
            'data_quality_flags' => $item->data_quality_flags ?? [],
            'has_medical_attention_identifier' => filled($item->medical_attention_identifier),
            'has_linked_user' => filled($item->linked_user_id),
            'has_linked_customer' => filled($item->linked_customer_id),
            'has_linked_odessa_account' => filled($item->linked_odessa_account_id),
            'has_other_famedic_email' => filled($item->metadata_json['other_famedic_email'] ?? null),
            'has_murguia_error' => filled($item->metadata_json['murguia_error'] ?? null),
            'show_url' => route('admin.odessa.pre-enrollments.show', $item, absolute: false),
        ];

        if ($includeIdentity) {
            $row['identity'] = $this->indexIdentityRow($item);
            $row['medical_attention_identifier'] = $this->safeDisplayText($item->medical_attention_identifier);
        }

        return $row;
    }

    private function indexIdentityRow(OdessaPreEnrollment $item): array
    {
        return [
            'full_name' => $this->safeDisplayText($item->full_name),
            'company' => $this->safeDisplayText($item->company_external_identifier),
            'employee_identifier_masked' => $this->maskEmployeeIdentifier($item->employee_identifier),
            'source_email_masked' => $this->maskEmail($item->source_email),
        ];
    }

    private function detailRow(OdessaPreEnrollment $item, Request $request): array
    {
        $canViewFullIdentity = $this->can($request, self::MANAGE_PERMISSION);

        return array_merge($this->indexRow($item), [
            'source_sheet' => $item->source_sheet,
            'membership_type' => $item->membership_type,
            'membership_start_date' => $item->membership_start_date?->toDateString(),
            'membership_end_date' => $item->membership_end_date?->toDateString(),
            'murguia_synced_at' => $item->murguia_synced_at?->toDateTimeString(),
            'murguia_pending_since' => $item->murguia_pending_since?->toDateTimeString(),
            'murguia_registration_acknowledged_at' => $item->murguia_registration_acknowledged_at?->toDateTimeString(),
            'murguia_checked_at' => $item->murguia_checked_at?->toDateTimeString(),
            'murguia_attempts' => (int) ($item->murguia_attempts ?? 0),
            'murguia_last_http_status' => $item->murguia_last_http_status,
            'murguia_last_event_code' => $item->murguia_last_event_code,
            'murguia_last_event_label' => $this->murguiaEventLabel($item->murguia_last_event_code),
            'blocked_reason' => $item->blocked_reason,
            ...($canViewFullIdentity ? ['medical_attention_identifier' => $this->safeDisplayText($item->medical_attention_identifier)] : []),
            'identity' => $canViewFullIdentity ? $this->detailFullIdentityRow($item) : $this->detailMinimizedIdentityRow($item),
            'matching' => [
                'flags' => $item->data_quality_flags ?? [],
                'blocked_reason' => $item->blocked_reason,
                'other_famedic_email_available' => filled($item->metadata_json['other_famedic_email'] ?? null),
                'murguia_error_available' => filled($item->metadata_json['murguia_error'] ?? null),
            ],
            'linked_user' => $item->linkedUser ? ['id' => $item->linkedUser->id, 'present' => true] : null,
            'linked_customer' => $item->linkedCustomer ? ['id' => $item->linkedCustomer->id, 'present' => true] : null,
            'linked_odessa_account' => $item->linkedOdessaAccount ? ['id' => $item->linkedOdessaAccount->id, 'present' => true] : null,
            'created_by' => $item->creator ? ['id' => $item->creator->id, 'present' => true] : null,
            'created_at' => $item->created_at?->toDateTimeString(),
            'updated_at' => $item->updated_at?->toDateTimeString(),
            'audits' => $item->audits->map(fn ($audit) => [
                'action_type' => $audit->action_type,
                'performed_by' => $audit->performer ? ['id' => $audit->performer->id, 'present' => true] : null,
                'performed_at' => $audit->performed_at?->toDateTimeString(),
                'summary' => $this->auditSummary($audit->before_json ?? [], $audit->after_json ?? []),
                'reason' => $audit->reason,
            ])->values(),
        ]);
    }

    private function detailFullIdentityRow(OdessaPreEnrollment $item): array
    {
        return [
            'access' => 'full',
            'full_name' => $this->safeDisplayText($item->full_name),
            'company' => $this->safeDisplayText($item->company_external_identifier),
            'employee_identifier' => $this->safeDisplayText($item->employee_identifier),
            'odessa_identifier' => $this->safeDisplayText($item->odessa_identifier),
            'masked_email' => $this->maskEmail($item->source_email),
            'birth_year' => $item->birth_date?->format('Y'),
            'source_action' => $item->source_action,
            'source_sheet' => $this->safeDisplayText($item->source_sheet),
            'source_row' => $item->source_row,
        ];
    }

    private function detailMinimizedIdentityRow(OdessaPreEnrollment $item): array
    {
        return [
            'access' => 'minimized',
            'name_initials' => $this->initials($item),
            'company_masked' => $this->maskIdentifier($item->company_external_identifier),
            'employee_identifier_masked' => $this->maskIdentifier($item->employee_identifier),
            'odessa_identifier_masked' => $this->maskIdentifier($item->odessa_identifier),
            'masked_email' => $this->maskEmail($item->source_email),
        ];
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('famedic.odessa_pre_enrollments.enabled', false), 404);
    }

    private function authorizeAccess(Request $request, string $permission): void
    {
        $this->can($request, $permission) || abort(403);
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

    private function canManageAction(Request $request, string $permission): bool
    {
        return $this->can($request, self::MANAGE_PERMISSION) && $this->can($request, $permission);
    }

    private function murguiaEndpointLabel(Request $request): ?string
    {
        if (! $this->canManageAction($request, self::MURGUIA_REGISTER_PERMISSION)
            && ! $this->canManageAction($request, self::MURGUIA_VERIFY_PERMISSION)
            && ! $this->canManageAction($request, self::MURGUIA_RETRY_PERMISSION)) {
            return null;
        }

        $url = trim((string) config('services.murguia.url', ''));
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $this->safeDisplayText($url);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        return $this->safeDisplayText($scheme.$parts['host'].$port.$path);
    }

    private function auditSummary(array $before, array $after): array
    {
        return [
            'credit_was_present' => (bool) ($before['has_medical_attention_identifier'] ?? false),
            'credit_is_present' => (bool) ($after['has_medical_attention_identifier'] ?? false),
            'status' => $after['status'] ?? null,
            'link_status' => $after['link_status'] ?? null,
            'murguia_status' => $after['murguia_status'] ?? null,
            'murguia_attempts' => $after['murguia_attempts'] ?? null,
            'murguia_last_event_code' => $after['murguia_last_event_code'] ?? $after['event_code'] ?? null,
            'http_status' => $after['http_status'] ?? null,
        ];
    }

    private function murguiaRedirect(OdessaPreEnrollment $preEnrollment, array $result, string $errorKey): RedirectResponse
    {
        $redirect = redirect()->route('admin.odessa.pre-enrollments.show', $preEnrollment);

        return ($result['ok'] ?? false)
            ? $redirect->with('success', $result['message'] ?? 'Operación Murguía procesada.')
            : $redirect->withErrors([$errorKey => $result['message'] ?? 'No se pudo procesar la operación Murguía.']);
    }

    private function murguiaEventLabel(?string $code): ?string
    {
        return match ($code) {
            'MURGUIA_REGISTER_STARTED' => 'Alta iniciada',
            'MURGUIA_REGISTER_ACCEPTED' => 'Alta aceptada',
            'MURGUIA_REGISTER_REJECTED' => 'Alta rechazada',
            'MURGUIA_REGISTER_OUTCOME_UNKNOWN' => 'Resultado desconocido',
            'MURGUIA_CONTRACT_NOT_CONFIGURED' => 'Contrato pendiente',
            'MURGUIA_MEMBERSHIP_DATES_MISMATCH' => 'Vigencia no coincide',
            'MURGUIA_READBACK_STARTED' => 'Verificación iniciada',
            'MURGUIA_READBACK_ACTIVE' => 'Activo confirmado',
            'MURGUIA_READBACK_INACTIVE' => 'Inactivo confirmado',
            'MURGUIA_READBACK_NOT_FOUND' => 'No encontrado',
            'MURGUIA_READBACK_UNKNOWN' => 'Verificación no concluyente',
            'MURGUIA_READBACK_FAILED' => 'Verificación fallida',
            'MURGUIA_RETRY_STARTED' => 'Reintento iniciado',
            'STALE_OPERATION_RESULT_IGNORED' => 'Respuesta anterior ignorada',
            default => null,
        };
    }

    private function maskIdentifier(?string $value): ?string
    {
        $value = OdessaPreEnrollment::normalizeIdentifier($value);
        if (! $value) {
            return null;
        }

        return str_repeat('*', max(0, strlen($value) - 2)).substr($value, -2);
    }

    private function maskEmployeeIdentifier(?string $value): ?string
    {
        $value = OdessaPreEnrollment::normalizeIdentifier($value);
        if (! $value) {
            return null;
        }

        $visibleLength = min(4, strlen($value));

        return $this->safeDisplayText(str_repeat('*', max(4, strlen($value) - $visibleLength)).substr($value, -$visibleLength));
    }

    private function maskEmail(?string $value): ?string
    {
        if (! $value || ! str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);
        $first = substr($local, 0, 1);

        return $this->safeDisplayText($first.'***@'.$domain);
    }

    private function safeDisplayText(mixed $value): ?string
    {
        $text = trim(strip_tags((string) $value));
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        if ($text === '') {
            return null;
        }

        return preg_match('/^[=+\-@]/', $text) ? "'".$text : $text;
    }

    private function initials(OdessaPreEnrollment $item): ?string
    {
        $parts = array_filter([$item->first_name, $item->paternal_last_name, $item->maternal_last_name]);
        if ($parts === []) {
            return null;
        }

        return implode('', array_map(fn (string $part) => mb_substr(trim($part), 0, 1), $parts));
    }
}
