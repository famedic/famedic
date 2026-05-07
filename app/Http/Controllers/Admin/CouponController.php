<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CouponApprovalStatus;
use App\Http\Controllers\Controller;
use App\Imports\CouponCampaignEmailsImport;
use App\Imports\CouponsExcelImport;
use App\Mail\CouponAuthorizationRequestedMail;
use App\Mail\CouponApprovalRequestMail;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\CouponAuditLog;
use App\Models\CouponBeneficiaryApprovalRule;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CouponController extends Controller
{
    public function __construct(
        private CouponService $couponService
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $coupons = $this->couponIndexQuery($request)
            ->paginate(20)
            ->withQueryString();

        $couponIds = $coupons->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $approvalSummaries = $this->couponIndexAssignmentApprovalSummaries($couponIds);
        $coupons->getCollection()->transform(function (Coupon $coupon) use ($approvalSummaries) {
            $coupon->setAttribute(
                'assignment_approval_summary',
                $approvalSummaries[$coupon->id] ?? null
            );

            return $coupon;
        });

        return Inertia::render('Admin/Coupons/Index', [
            'coupons' => $coupons,
            'filters' => [
                'search' => $request->input('search', ''),
                'usage' => $request->input('usage', 'all'),
                'user_email' => $request->input('user_email', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
            ],
            'authorizerContext' => $this->authorizerCouponsListContext($request),
            'approvalsOverview' => [
                'pending_assignment_requests' => CouponApprovalRequest::query()
                    ->where('status', 'pending')
                    ->where('type', 'assignment')
                    ->whereNotNull('coupon_id')
                    ->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $filename = 'cupones-saldo-'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID',
                'Código',
                'Descripción',
                'Creado',
                'Monto (centavos)',
                'Restante (centavos)',
                'Máx. beneficiarios',
                'Estado aprobación',
                'Activo',
                'Asignaciones directas',
                'Cupones hijo (campaña)',
            ]);

            foreach ($this->couponIndexQuery($request)->cursor() as $c) {
                fputcsv($handle, [
                    $c->id,
                    $c->code,
                    $c->description,
                    $c->created_at?->toIso8601String(),
                    $c->amount_cents,
                    $c->remaining_cents,
                    $c->max_beneficiaries,
                    $c->approval_status?->value,
                    $c->is_active ? '1' : '0',
                    $c->coupon_users_count,
                    $c->child_coupons_count,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function settings(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $authorizers = Administrator::role('autorizador')
            ->with('user:id,name,paternal_lastname,maternal_lastname,email')
            ->orderByUserName()
            ->get()
            ->map(fn (Administrator $administrator) => [
                'id' => $administrator->id,
                'name' => $administrator->user?->full_name ?: 'Sin nombre',
                'email' => $administrator->user?->email,
            ]);

        $tab = (string) $request->query('tab', 'limits');
        $allowedTabs = ['limits', 'approval', 'authorizers'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'limits';
        }

        return Inertia::render('Admin/Coupons/Settings', [
            'settings' => CouponAdminSettings::singleton(),
            'authorizers' => $authorizers,
            'initialTab' => $tab,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorize('configure', Coupon::class);

        $data = $request->validate([
            'base_amount_mxn' => ['nullable', 'numeric', 'min:0'],
            'max_assignment_amount_mxn' => ['nullable', 'numeric', 'min:0'],
            'max_assignments_per_day' => ['nullable', 'integer', 'min:1'],
            'authorization_email' => ['nullable', 'email'],
            'require_authorization' => ['boolean'],
            'amount_threshold_mxn' => ['nullable', 'numeric', 'min:0'],
            'required_approvals_by_amount' => ['nullable', 'integer', 'min:0'],
            'mass_campaign_threshold' => ['nullable', 'integer', 'min:1'],
            'superadmin_bypass_approvals' => ['boolean'],
            'beneficiary_rules' => ['nullable', 'array'],
            'beneficiary_rules.*.min' => ['required_with:beneficiary_rules', 'integer', 'min:1'],
            'beneficiary_rules.*.max' => ['nullable', 'integer', 'min:1'],
            'beneficiary_rules.*.required_approvals' => ['required_with:beneficiary_rules', 'integer', 'min:0'],
            'authorizer_ids' => ['nullable', 'array'],
            'authorizer_ids.*' => [
                'integer',
                Rule::exists('administrators', 'id'),
            ],
        ]);

        $settings = CouponAdminSettings::singleton();
        $before = [
            'base_amount_cents' => $settings->base_amount_cents,
            'max_assignment_amount_cents' => $settings->max_assignment_amount_cents,
            'max_assignments_per_day' => $settings->max_assignments_per_day,
            'authorization_email' => $settings->authorization_email,
            'require_authorization' => $settings->require_authorization,
            'amount_threshold_cents' => $settings->amount_threshold_cents,
            'required_approvals_by_amount' => $settings->required_approvals_by_amount,
            'mass_campaign_threshold' => $settings->mass_campaign_threshold,
            'superadmin_bypass_approvals' => $settings->superadmin_bypass_approvals,
            'beneficiary_rules' => CouponBeneficiaryApprovalRule::query()->orderBy('min_beneficiaries')->get()->toArray(),
        ];

        $after = [
            'base_amount_cents' => isset($data['base_amount_mxn']) && $data['base_amount_mxn'] !== null && $data['base_amount_mxn'] !== ''
                ? (int) round((float) $data['base_amount_mxn'] * 100)
                : $settings->base_amount_cents,
            'max_assignment_amount_cents' => isset($data['max_assignment_amount_mxn']) && $data['max_assignment_amount_mxn'] !== null && $data['max_assignment_amount_mxn'] !== ''
            ? (int) round((float) $data['max_assignment_amount_mxn'] * 100)
            : null,
            'max_assignments_per_day' => $data['max_assignments_per_day'] ?? null,
            'authorization_email' => isset($data['authorization_email'])
                ? trim((string) $data['authorization_email']) : null,
        ];
        if ($after['authorization_email'] === '') {
            $after['authorization_email'] = null;
        }
        $after['require_authorization'] = $data['require_authorization'] ?? false;
        $after['amount_threshold_cents'] = isset($data['amount_threshold_mxn']) && $data['amount_threshold_mxn'] !== null && $data['amount_threshold_mxn'] !== ''
            ? (int) round((float) $data['amount_threshold_mxn'] * 100)
            : null;
        $after['required_approvals_by_amount'] = (int) ($data['required_approvals_by_amount'] ?? 0);
        $after['mass_campaign_threshold'] = $data['mass_campaign_threshold'] ?? null;
        $after['superadmin_bypass_approvals'] = $data['superadmin_bypass_approvals'] ?? true;
        $after['beneficiary_rules'] = collect($data['beneficiary_rules'] ?? [])
            ->map(fn ($rule) => [
                'min_beneficiaries' => (int) $rule['min'],
                'max_beneficiaries' => isset($rule['max']) && $rule['max'] !== '' ? (int) $rule['max'] : null,
                'required_approvals' => (int) $rule['required_approvals'],
            ])->values()->all();

        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        if (! $isSuperadmin) {
            $authorizerIds = collect($data['authorizer_ids'] ?? [])->map(fn ($v) => (int) $v)->filter()->values()->all();
            if (count($authorizerIds) === 0) {
                return redirect()->back()->flashMessage(
                    'Selecciona al menos un autorizador para enviar la solicitud de cambio.',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'settings',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: max(1, min(count($authorizerIds), (int) $after['required_approvals_by_amount'] ?: 1)),
                authorizerAdministratorIds: $authorizerIds,
                beforeState: $before,
                afterState: $after
            );

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.settings')->flashMessage(
                "Se creó la solicitud #{$approvalRequest->id}. Los cambios se aplicarán al completar las aprobaciones requeridas."
            );
        }

        $settings->fill($after);
        $settings->save();

        CouponBeneficiaryApprovalRule::query()->delete();
        foreach ($after['beneficiary_rules'] as $rule) {
            CouponBeneficiaryApprovalRule::create($rule);
        }

        $this->couponService->logConfiguration((int) $request->user()->id, $before, $after, 'completed');

        return redirect()->route('admin.coupons.settings')->flashMessage('Reglas de cupones actualizadas.');
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        return redirect()->route('admin.coupons.assign', ['focus' => 'new']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        $adminSettings = CouponAdminSettings::singleton();

        if ($adminSettings->max_assignment_amount_cents !== null
            && $data['amount_cents'] > $adminSettings->max_assignment_amount_cents) {
            return redirect()->back()->flashMessage(
                'El monto supera el máximo configurado en las reglas de cupones.',
                'error'
            );
        }

        $pending = $adminSettings->require_authorization;
        if ($pending && trim((string) $adminSettings->authorization_email) === '') {
            return redirect()->back()->flashMessage(
                'Configura el correo del autorizador en Reglas de cupones antes de exigir autorización.',
                'error'
            );
        }

        $this->couponService->assertAssignmentRules($data['amount_cents']);

        $coupon = Coupon::create([
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'amount_cents' => $data['amount_cents'],
            'remaining_cents' => $pending ? 0 : $data['amount_cents'],
            'max_beneficiaries' => $data['max_beneficiaries'] ?? null,
            'type' => 'balance',
            'is_active' => $pending ? false : ($data['is_active'] ?? true),
            'approval_status' => $pending ? CouponApprovalStatus::PendingAuthorization : CouponApprovalStatus::Active,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        if ($pending) {
            $plain = (string) random_int(100000, 999999);
            $coupon->authorization_code_hash = Hash::make($plain);
            $coupon->authorization_code_expires_at = $this->authorizationCodeExpiresAt();
            $coupon->save();

            $sent = $this->sendAuthorizationMailToArbitrator($coupon, $plain);
            if (! $sent['ok']) {
                return redirect()->route('admin.coupons.index')->flashMessage(
                    'Cupón registrado, pero no se pudo enviar el correo: '.$sent['error'],
                    'error'
                );
            }

            return redirect()->route('admin.coupons.index')->flashMessage(
                'Cupón registrado. Se envió un código de autorización al correo del autorizador.'.$this->mailDriverNoticeForSuccessMessage()
            );
        }

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón creado.');
    }

    public function show(Request $request, Coupon $coupon): InertiaResponse|RedirectResponse
    {
        $this->authorize('view', $coupon);

        if ($coupon->parent_coupon_id) {
            return redirect()->route('admin.coupons.show', $coupon->parent_coupon_id);
        }

        $coupon->load([
            'createdByUser:id,name,paternal_lastname,maternal_lastname,email',
            'updatedByUser:id,name,paternal_lastname,maternal_lastname,email',
            'authorizedByUser:id,name,paternal_lastname,maternal_lastname,email',
            'transactions',
            'couponUsers' => fn ($q) => $q->orderBy('assigned_at'),
            'couponUsers.user:id,name,paternal_lastname,maternal_lastname,email',
            'childCoupons.couponUsers.user:id,name,paternal_lastname,maternal_lastname,email',
            'childCoupons.transactions',
        ]);

        $beneficiaryRows = [];

        foreach ($coupon->childCoupons->sortBy('id') as $child) {
            $assignment = $child->couponUsers->first();
            $user = $assignment?->user;
            $tx = $child->transactions->first();
            $beneficiaryRows[] = [
                'assignment_id' => $assignment?->id,
                'coupon_id' => $child->id,
                'user' => $user,
                'assigned_at' => $assignment?->assigned_at,
                'used_at' => $assignment?->used_at,
                'remaining_cents' => $child->remaining_cents,
                'transaction' => $tx ? [
                    'amount_used_cents' => $tx->amount_used_cents,
                    'purchase_type' => $tx->purchase_type->value,
                    'purchase_url' => $this->purchaseAdminUrl($tx),
                ] : null,
            ];
        }

        foreach ($coupon->couponUsers as $assignment) {
            $user = $assignment->user;
            $tx = $coupon->transactions->first();
            $beneficiaryRows[] = [
                'assignment_id' => $assignment->id,
                'coupon_id' => $coupon->id,
                'user' => $user,
                'assigned_at' => $assignment->assigned_at,
                'used_at' => $assignment->used_at,
                'remaining_cents' => $coupon->remaining_cents,
                'transaction' => $tx ? [
                    'amount_used_cents' => $tx->amount_used_cents,
                    'purchase_type' => $tx->purchase_type->value,
                    'purchase_url' => $this->purchaseAdminUrl($tx),
                ] : null,
            ];
        }

        usort($beneficiaryRows, fn ($a, $b) => ($a['assigned_at'] ?? '') <=> ($b['assigned_at'] ?? ''));

        $userIds = collect($beneficiaryRows)->pluck('user')->filter()->pluck('id')->unique()->filter();
        $customerIdsByUserId = Customer::query()->whereIn('user_id', $userIds)->pluck('id', 'user_id');

        $beneficiaryRows = array_map(function (array $row) use ($customerIdsByUserId) {
            $uid = $row['user']['id'] ?? null;
            $cid = $uid ? $customerIdsByUserId->get($uid) : null;
            $row['customer_admin_url'] = $cid
                ? route('admin.customers.show', ['customer' => $cid], absolute: false)
                : null;

            return $row;
        }, $beneficiaryRows);

        $pendingAuth = $coupon->approval_status === CouponApprovalStatus::PendingAuthorization;
        $settings = CouponAdminSettings::singleton();

        return Inertia::render('Admin/Coupons/Show', [
            'coupon' => $coupon,
            'beneficiaryRows' => $beneficiaryRows,
            'authorizationRecipientEmail' => $settings->authorization_email,
            'mailSetupHint' => $pendingAuth ? $this->mailSetupHintForCouponShow() : null,
            'authorizers' => Administrator::role('autorizador')
                ->with('user:id,name,paternal_lastname,maternal_lastname,email')
                ->orderByUserName()
                ->get()
                ->map(fn (Administrator $administrator) => [
                    'id' => $administrator->id,
                    'name' => $administrator->user?->full_name ?: 'Sin nombre',
                    'email' => $administrator->user?->email,
                ]),
            'rulesForUi' => $this->couponRulesForAssignUi($settings),
            'isSuperadmin' => (bool) $request->user()->administrator?->hasRole('superadmin'),
            'assignmentMultiSig' => $this->assignmentMultiSigProgressForCoupon($request, $coupon),
            'executedPreApprovalSummary' => $this->executedPreApprovalSummaryForCoupon($coupon),
        ]);
    }

    private function purchaseAdminUrl(\App\Models\CouponTransaction $t): string
    {
        return match ($t->purchase_type->value) {
            'lab' => route('admin.laboratory-purchases.show', ['laboratory_purchase' => $t->purchase_id], absolute: false),
            'pharmacy' => route('admin.online-pharmacy-purchases.show', ['online_pharmacy_purchase' => $t->purchase_id], absolute: false),
            default => '#',
        };
    }

    public function authorizeCoupon(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        try {
            $this->couponService->authorizePendingCoupon($coupon, trim($data['code']), (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()->route('admin.coupons.show', $coupon)->flashMessage('Cupón autorizado. Ya puedes asignar beneficiarios.');
    }

    public function resendAuthorization(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);

        if ($coupon->parent_coupon_id !== null) {
            abort(404);
        }

        if ($coupon->approval_status !== CouponApprovalStatus::PendingAuthorization) {
            return redirect()->back()->flashMessage(
                'Solo se puede reenviar el código mientras el cupón está pendiente de autorización.',
                'error'
            );
        }

        $settings = CouponAdminSettings::singleton();
        if (trim((string) $settings->authorization_email) === '') {
            return redirect()->back()->flashMessage(
                'Configura el correo del autorizador en Reglas de cupones.',
                'error'
            );
        }

        $plain = (string) random_int(100000, 999999);
        $coupon->authorization_code_hash = Hash::make($plain);
        $coupon->authorization_code_expires_at = $this->authorizationCodeExpiresAt();
        $coupon->updated_by_user_id = $request->user()->id;
        $coupon->save();

        $sent = $this->sendAuthorizationMailToArbitrator($coupon, $plain);
        if (! $sent['ok']) {
            return redirect()->back()->flashMessage(
                'No se pudo enviar el correo: '.$sent['error'],
                'error'
            );
        }

        return redirect()->back()->flashMessage(
            'Se envió un nuevo código al correo del autorizador. El código anterior ya no es válido.'.$this->mailDriverNoticeForSuccessMessage()
        );
    }

    public function edit(Coupon $coupon): InertiaResponse
    {
        $this->authorize('update', $coupon);
        if ($this->couponIsLockedAfterApproval($coupon)) {
            abort(403, 'Este cupón quedó bloqueado después de su aprobación y ya no se puede editar.');
        }

        return Inertia::render('Admin/Coupons/Edit', [
            'coupon' => $coupon,
        ]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);
        if ($this->couponIsLockedAfterApproval($coupon)) {
            return redirect()->route('admin.coupons.show', $coupon)->flashMessage(
                'Este cupón quedó bloqueado después de su aprobación y ya no se puede editar.',
                'error'
            );
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        $coupon->code = $data['code'] ?? null;
        $coupon->description = $data['description'] ?? null;
        $coupon->max_beneficiaries = $data['max_beneficiaries'] ?? null;
        $coupon->is_active = $data['is_active'] ?? $coupon->is_active;
        $coupon->updated_by_user_id = $request->user()->id;
        $coupon->save();

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón actualizado.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->authorize('delete', $coupon);

        $coupon->is_active = false;
        $coupon->updated_by_user_id = request()->user()->id;
        $coupon->save();

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón desactivado.');
    }

    public function assignForm(Request $request): InertiaResponse
    {
        $this->authorize('create', Coupon::class);

        $assignable = Coupon::query()
            ->rootCoupons()
            ->where('approval_status', CouponApprovalStatus::Active)
            ->where('is_active', true)
            ->withCount('childCoupons')
            ->orderByDesc('id')
            ->get()
            ->filter(function (Coupon $c) {
                if ($c->max_beneficiaries === null) {
                    return true;
                }

                return $c->child_coupons_count < $c->max_beneficiaries;
            })
            ->values();

        $settings = CouponAdminSettings::singleton();

        $tab = (string) $request->query('tab', 'coupon');
        $allowedTabs = ['coupon', 'assignment', 'summary'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'coupon';
        }

        return Inertia::render('Admin/Coupons/Assign', [
            'assignableCoupons' => $assignable,
            'settings' => $settings,
            'rulesForUi' => $this->couponRulesForAssignUi($settings),
            'authorizers' => Administrator::role('autorizador')
                ->with('user:id,name,paternal_lastname,maternal_lastname,email')
                ->orderByUserName()
                ->get()
                ->map(fn (Administrator $administrator) => [
                    'id' => $administrator->id,
                    'name' => $administrator->user?->full_name ?: 'Sin nombre',
                    'email' => $administrator->user?->email,
                ]),
            'focus' => $request->query('focus', ''),
            'initialTab' => $tab,
            'isSuperadmin' => (bool) $request->user()->administrator?->hasRole('superadmin'),
        ]);
    }

    public function lookupAssignableUser(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->select(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'email'])
            ->where('email', trim((string) $data['email']))
            ->first();

        return response()->json([
            'exists' => (bool) $user,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->full_name ?: trim(implode(' ', array_filter([
                    $user->name,
                    $user->paternal_lastname,
                    $user->maternal_lastname,
                ]))),
                'email' => $user->email,
            ] : null,
        ]);
    }

    /**
     * Lee el archivo masivo y devuelve cada correo con indicador si existe usuario en la plataforma.
     */
    public function previewBulkAssignEmails(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new CouponCampaignEmailsImport;
        Excel::import($import, $request->file('file'));
        $emails = $import->getUniqueEmails();

        if ($emails === []) {
            return response()->json([
                'message' => 'No se encontraron correos válidos. Usa la columna email o correo.',
                'rows' => [],
            ], 422);
        }

        $maxRows = 5000;
        if (count($emails) > $maxRows) {
            return response()->json([
                'message' => "El archivo supera el máximo de {$maxRows} correos distintos.",
                'rows' => [],
            ], 422);
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $users = User::query()
            ->select(['email', 'name', 'paternal_lastname', 'maternal_lastname'])
            ->whereRaw('LOWER(TRIM(email)) IN ('.$placeholders.')', $emails)
            ->get();

        $byLower = [];
        foreach ($users as $user) {
            $byLower[strtolower(trim((string) $user->email))] = $user;
        }

        $rows = [];
        foreach ($emails as $email) {
            $user = $byLower[$email] ?? null;
            $rows[] = [
                'email' => $email,
                'exists' => $user !== null,
                'user_name' => $user ? ($user->full_name ?: trim(implode(' ', array_filter([
                    $user->name,
                    $user->paternal_lastname,
                    $user->maternal_lastname,
                ])))) : null,
            ];
        }

        $matched = count(array_filter($rows, fn (array $r) => $r['exists']));

        return response()->json([
            'rows' => $rows,
            'total' => count($rows),
            'matched' => $matched,
            'unmatched' => count($rows) - $matched,
        ]);
    }

    /**
     * Descarga CSV de ejemplo para asignación masiva (columna email o correo).
     */
    public function downloadBulkAssignTemplate(): StreamedResponse
    {
        $this->authorize('create', Coupon::class);

        $path = resource_path('templates/coupon_bulk_assign_example.csv');
        if (! is_readable($path)) {
            abort(404, 'Plantilla no encontrada.');
        }

        return response()->streamDownload(function () use ($path) {
            echo "\xEF\xBB\xBF";
            readfile($path);
        }, 'plantilla_carga_beneficiarios_cupones.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'coupon_mode' => ['required', Rule::in(['existing', 'new'])],
            'assignment_mode' => ['required', Rule::in(['none', 'individual', 'bulk'])],
            'coupon_id' => ['required_if:coupon_mode,existing', 'nullable', 'integer', Rule::exists('coupons', 'id')],
            'email' => ['required_if:assignment_mode,individual', 'nullable', 'email', 'exists:users,email'],
            'file' => [
                'nullable',
                'file',
                'mimes:xlsx,xls,csv',
                Rule::requiredIf(fn () => $request->input('assignment_mode') === 'bulk'
                    && empty($request->input('bulk_emails'))),
            ],
            'bulk_emails' => ['nullable', 'array', 'max:5000'],
            'bulk_emails.*' => ['email', 'max:255'],
            'send_notification' => ['boolean'],
            'send_notifications' => ['boolean'],
            'amount_cents' => ['required_if:coupon_mode,new', 'nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'authorizer_ids' => ['nullable', 'array'],
            'authorizer_ids.*' => ['integer', Rule::exists('administrators', 'id')],
        ]);

        $sendForIndividual = $data['send_notification'] ?? true;
        $sendForBulk = $data['send_notifications'] ?? $data['send_notification'] ?? true;

        $parent = null;

        if ($data['coupon_mode'] === 'new') {
            $beneficiariesForPreApprovalDraft = isset($data['max_beneficiaries']) && $data['max_beneficiaries'] !== null
                ? max(1, (int) $data['max_beneficiaries'])
                : 1;
            $requiredPreApprovalsDraft = $this->couponService->resolveRequiredApprovals(
                (int) $data['amount_cents'],
                $beneficiariesForPreApprovalDraft
            );
            $isSuperadminDraft = (bool) $request->user()->administrator?->hasRole('superadmin');
            $settingsDraft = CouponAdminSettings::singleton();
            $relaxMaxForPreApproval = $data['assignment_mode'] === 'none'
                && $requiredPreApprovalsDraft > 0
                && ! ($isSuperadminDraft && $settingsDraft->superadmin_bypass_approvals);

            $parent = $this->persistMasterCouponForAssign($request, $data, $relaxMaxForPreApproval);
            if ($parent->approval_status === CouponApprovalStatus::PendingAuthorization) {
                if ($data['assignment_mode'] !== 'none') {
                    return redirect()->route('admin.coupons.assign')->flashMessage(
                        'El cupón quedó pendiente de autorización. Cuando esté activo, vuelve aquí para asignar beneficiarios o usa el listado de cupones.',
                        'error'
                    );
                }

                return redirect()->route('admin.coupons.show', $parent)->flashMessage(
                    'Cupón creado. Completa la autorización para poder asignar beneficiarios.'
                );
            }
        } else {
            $parent = Coupon::query()
                ->rootCoupons()
                ->whereKey((int) $data['coupon_id'])
                ->firstOrFail();

            if ($parent->approval_status !== CouponApprovalStatus::Active || ! $parent->is_active) {
                return redirect()->back()->flashMessage(
                    'El cupón seleccionado no está disponible para asignaciones.',
                    'error'
                );
            }

            if ($this->couponHasPendingPreApprovalLock((int) $parent->id)) {
                return redirect()->back()->flashMessage(
                    'Este cupón está bloqueado para asignaciones hasta completar las aprobaciones requeridas.',
                    'error'
                );
            }
        }

        if ($data['assignment_mode'] === 'none') {
            $beneficiariesForPreApproval = isset($data['max_beneficiaries']) && $data['max_beneficiaries'] !== null
                ? max(1, (int) $data['max_beneficiaries'])
                : 1;
            $requiredPreApprovals = $this->couponService->resolveRequiredApprovals((int) $parent->amount_cents, $beneficiariesForPreApproval);
            $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
            $settings = CouponAdminSettings::singleton();

            if ($requiredPreApprovals > 0 && ! ($isSuperadmin && $settings->superadmin_bypass_approvals)) {
                $authorizerIds = collect($data['authorizer_ids'] ?? [])->map(fn ($v) => (int) $v)->filter()->values()->all();
                if (count($authorizerIds) === 0) {
                    $authorizerIds = $this->defaultAuthorizerAdministratorIds();
                }
                if (count($authorizerIds) === 0) {
                    return redirect()->back()->flashMessage(
                        'Este cupón requiere aprobación antes de asignar beneficiarios, pero no hay autorizadores configurados.',
                        'error'
                    );
                }

                $approvalRequest = $this->couponService->createApprovalRequest(
                    type: 'assignment',
                    requestedByUserId: (int) $request->user()->id,
                    requiredApprovals: min($requiredPreApprovals, count($authorizerIds)),
                    authorizerAdministratorIds: $authorizerIds,
                    payload: [
                        'coupon_id' => $parent->id,
                        'pre_approval_only' => true,
                        'required_by_rules' => $requiredPreApprovals,
                        'beneficiaries_for_rule' => $beneficiariesForPreApproval,
                    ],
                    couponId: (int) $parent->id
                );
                $this->sendApprovalRequestMails($approvalRequest);

                return redirect()->route('admin.coupons.show', $parent)->flashMessage(
                    "Se creó la solicitud #{$approvalRequest->id} para aprobar este cupón antes de asignar beneficiarios."
                );
            }

            return redirect()->route('admin.coupons.show', $parent)->flashMessage(
                'Cupón listo. Puedes asignar beneficiarios cuando lo necesites.'
            );
        }

        if ($data['assignment_mode'] === 'individual') {
            $user = User::where('email', $data['email'])->firstOrFail();

            return $this->finishCampaignAssignment(
                $request,
                $parent,
                $user,
                $sendForIndividual,
                1
            );
        }

        $emails = collect($request->input('bulk_emails', []))
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            if (! $request->hasFile('file')) {
                return redirect()->back()->flashMessage(
                    'En modo masivo debes analizar el archivo en vista previa y confirmar correos, o adjuntar el archivo.',
                    'error'
                );
            }
            $import = new CouponCampaignEmailsImport;
            Excel::import($import, $request->file('file'));
            $emails = $import->getUniqueEmails();
        }

        if ($emails === []) {
            return redirect()->back()->flashMessage(
                'No se encontraron correos válidos en el archivo. Usa la columna email o correo.',
                'error'
            );
        }

        return $this->finishBulkCampaignAssignment(
            $request,
            $parent,
            $emails,
            $sendForBulk
        );
    }

    public function importForm(): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        return redirect()->route('admin.coupons.assign', ['focus' => 'bulk']);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'send_notifications' => ['boolean'],
        ]);

        Excel::import(
            new CouponsExcelImport($this->couponService, $request->boolean('send_notifications'), (int) $request->user()->id),
            $request->file('file')
        );

        return redirect()->route('admin.coupons.index')->flashMessage('Importación procesada.');
    }

    public function logs(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $query = CouponAuditLog::query()->with(['coupon', 'request', 'actorUser'])->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('user_id')) {
            $query->where('actor_user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return Inertia::render('Admin/Coupons/Logs', [
            'logs' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['type', 'user_id', 'date_from', 'date_to']),
        ]);
    }

    public function approveRequest(Request $request, CouponApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->authorize('approveRequests', Coupon::class);

        $administrator = $request->user()->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            abort(403);
        }

        $row = CouponApprovalRequestAuthorizer::query()
            ->where('coupon_approval_request_id', $approvalRequest->id)
            ->where('administrator_id', $administrator->id)
            ->firstOrFail();

        if ($approvalRequest->status !== 'pending') {
            return redirect()->back()->flashMessage('La solicitud ya fue procesada.', 'error');
        }

        $row->update([
            'status' => 'approved',
            'user_id' => $request->user()->id,
            'acted_at' => now(),
        ]);

        $approvalRequest->current_approvals = CouponApprovalRequestAuthorizer::query()
            ->where('coupon_approval_request_id', $approvalRequest->id)
            ->where('status', 'approved')
            ->count();

        if ($approvalRequest->current_approvals >= $approvalRequest->required_approvals) {
            if ($approvalRequest->type === 'settings') {
                $settings = CouponAdminSettings::singleton();
                $after = $approvalRequest->after_state ?? [];
                $rules = $after['beneficiary_rules'] ?? [];
                unset($after['beneficiary_rules']);
                $settings->fill($after);
                $settings->save();

                CouponBeneficiaryApprovalRule::query()->delete();
                foreach ($rules as $rule) {
                    CouponBeneficiaryApprovalRule::create($rule);
                }
            } elseif ($approvalRequest->type === 'assignment') {
                $payload = $approvalRequest->payload ?? [];
                $coupon = Coupon::query()->findOrFail((int) ($payload['coupon_id'] ?? 0));
                $sendNotification = (bool) ($payload['send_notification'] ?? true);
                $requestedBy = (int) $approvalRequest->requested_by_user_id;

                if (! empty($payload['pre_approval_only'])) {
                    $approvalRequest->status = 'executed';
                    $approvalRequest->executed_at = now();
                    $approvalRequest->save();

                    return redirect()->back()->flashMessage('Solicitud aprobada.');
                }

                if (! empty($payload['emails']) && is_array($payload['emails'])) {
                    foreach ($payload['emails'] as $email) {
                        $user = User::query()->where('email', (string) $email)->first();
                        if (! $user) {
                            continue;
                        }
                        try {
                            $this->couponService->assignUserToCampaignCoupon(
                                $user,
                                $coupon,
                                $sendNotification,
                                $requestedBy,
                                enforceMaxAssignmentAmount: false
                            );
                        } catch (\DomainException) {
                            continue;
                        }
                    }
                } else {
                    $user = User::query()->where('email', (string) ($payload['email'] ?? ''))->firstOrFail();
                    $this->couponService->assignUserToCampaignCoupon(
                        $user,
                        $coupon,
                        $sendNotification,
                        $requestedBy,
                        enforceMaxAssignmentAmount: false
                    );
                }
            }

            $approvalRequest->status = 'executed';
            $approvalRequest->executed_at = now();
        } else {
            // Mantener la solicitud pendiente hasta reunir todas las aprobaciones.
            $approvalRequest->status = 'pending';
        }

        $approvalRequest->save();

        return redirect()->back()->flashMessage('Solicitud aprobada.');
    }

    public function rejectRequest(Request $request, CouponApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->authorize('approveRequests', Coupon::class);

        $administrator = $request->user()->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            abort(403);
        }

        $row = CouponApprovalRequestAuthorizer::query()
            ->where('coupon_approval_request_id', $approvalRequest->id)
            ->where('administrator_id', $administrator->id)
            ->firstOrFail();

        $row->update([
            'status' => 'rejected',
            'user_id' => $request->user()->id,
            'acted_at' => now(),
        ]);

        $approvalRequest->status = 'rejected';
        $approvalRequest->rejected_by_user_id = $request->user()->id;
        $approvalRequest->rejected_at = now();
        $approvalRequest->save();

        return redirect()->back()->flashMessage('Solicitud rechazada.');
    }

    public function destroyAssignment(Coupon $coupon, CouponUser $couponUser): RedirectResponse
    {
        $this->authorize('update', $coupon);

        if ($couponUser->coupon_id !== $coupon->id) {
            abort(404);
        }

        try {
            $this->couponService->revokeAssignment($couponUser);
        } catch (\DomainException $e) {
            return redirect()
                ->route('admin.coupons.index')
                ->flashMessage($e->getMessage(), 'error');
        }

        return redirect()->back()->flashMessage('Asignación eliminada. El cupón quedó desactivado si ya no tenía destinatarios.');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array{
     *     is_authorizer: bool,
     *     pending_assignment_approvals_count: int,
     *     pending_my_action_coupon_ids: list<int>,
     *     pending_settings_approvals_count: int
     * }
     */
    private function authorizerCouponsListContext(Request $request): array
    {
        $administrator = $request->user()->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            return [
                'is_authorizer' => false,
                'pending_assignment_approvals_count' => 0,
                'pending_my_action_coupon_ids' => [],
                'pending_settings_approvals_count' => 0,
            ];
        }

        $adminId = (int) $administrator->id;

        $pendingAssignmentIds = CouponApprovalRequest::query()
            ->where('status', 'pending')
            ->where('type', 'assignment')
            ->whereNotNull('coupon_id')
            ->whereHas('authorizers', fn ($q) => $q->where('administrator_id', $adminId)->where('status', 'pending'))
            ->pluck('id');

        $pending_assignment_approvals_count = $pendingAssignmentIds->count();
        $pending_my_action_coupon_ids = CouponApprovalRequest::query()
            ->whereIn('id', $pendingAssignmentIds)
            ->pluck('coupon_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();

        $pending_settings_approvals_count = CouponApprovalRequest::query()
            ->where('status', 'pending')
            ->where('type', 'settings')
            ->whereHas('authorizers', fn ($q) => $q->where('administrator_id', $adminId)->where('status', 'pending'))
            ->count();

        return [
            'is_authorizer' => true,
            'pending_assignment_approvals_count' => $pending_assignment_approvals_count,
            'pending_my_action_coupon_ids' => $pending_my_action_coupon_ids,
            'pending_settings_approvals_count' => $pending_settings_approvals_count,
        ];
    }

    /**
     * Solicitud multi-firma de tipo assignment pendiente para este cupón (visible para cualquier usuario con acceso al cupón).
     *
     * @return array<string, mixed>|null
     */
    private function assignmentMultiSigProgressForCoupon(Request $request, Coupon $coupon): ?array
    {
        $approvalRequest = CouponApprovalRequest::query()
            ->where('coupon_id', $coupon->id)
            ->where('status', 'pending')
            ->where('type', 'assignment')
            ->with([
                'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers' => fn ($q) => $q->orderBy('id'),
                'authorizers.administrator.user:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers.actedByUser:id,name,paternal_lastname,maternal_lastname,email',
            ])
            ->orderByDesc('id')
            ->first();

        if (! $approvalRequest) {
            return null;
        }

        $administrator = $request->user()->administrator;
        $myAdminId = $administrator ? (int) $administrator->id : null;
        $iAmAuthorizer = $administrator && $administrator->hasRole('autorizador');

        $participants = $approvalRequest->authorizers->map(function (CouponApprovalRequestAuthorizer $row) use ($myAdminId) {
            $adminUser = $row->administrator?->user;
            $actor = $row->actedByUser;

            return [
                'administrator_id' => $row->administrator_id,
                'label' => $adminUser?->full_name ?: ($adminUser?->email ? $adminUser->email : 'Autorizador #'.$row->administrator_id),
                'email' => $adminUser?->email,
                'status' => $row->status,
                'is_me' => $myAdminId !== null && (int) $row->administrator_id === $myAdminId,
                'acted_at' => $row->acted_at?->toIso8601String(),
                'acted_by' => $actor ? [
                    'name' => $actor->full_name,
                    'email' => $actor->email,
                ] : null,
            ];
        })->values()->all();

        $requestedBy = $approvalRequest->requestedByUser;

        $myRowPending = $iAmAuthorizer && $approvalRequest->authorizers->contains(
            fn (CouponApprovalRequestAuthorizer $r) => (int) $r->administrator_id === $myAdminId && $r->status === 'pending'
        );

        $required = (int) $approvalRequest->required_approvals;
        $current = (int) $approvalRequest->current_approvals;

        return [
            'id' => $approvalRequest->id,
            'required_approvals' => $required,
            'current_approvals' => $current,
            'remaining_approvals' => max(0, $required - $current),
            'pre_approval_only' => (bool) ($approvalRequest->payload['pre_approval_only'] ?? false),
            'requested_by' => $requestedBy ? [
                'name' => $requestedBy->full_name,
                'email' => $requestedBy->email,
            ] : null,
            'participants' => $participants,
            'i_can_approve' => $myRowPending,
        ];
    }

    /**
     * Pre-aprobación multi-firma ya ejecutada: autorizadores que firmaron (última solicitud).
     *
     * @return array<string, mixed>|null
     */
    private function executedPreApprovalSummaryForCoupon(Coupon $coupon): ?array
    {
        $approvalRequest = CouponApprovalRequest::query()
            ->where('coupon_id', $coupon->id)
            ->where('status', 'executed')
            ->where('type', 'assignment')
            ->where('payload->pre_approval_only', true)
            ->with([
                'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers' => fn ($q) => $q->orderBy('id'),
                'authorizers.administrator.user:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers.actedByUser:id,name,paternal_lastname,maternal_lastname,email',
            ])
            ->orderByDesc('id')
            ->first();

        if (! $approvalRequest) {
            return null;
        }

        $approvers = $approvalRequest->authorizers
            ->filter(fn (CouponApprovalRequestAuthorizer $row) => $row->status === 'approved')
            ->map(function (CouponApprovalRequestAuthorizer $row) {
                $adminUser = $row->administrator?->user;
                $actor = $row->actedByUser;

                return [
                    'label' => $adminUser?->full_name ?: ($adminUser?->email ? $adminUser->email : 'Autorizador #'.$row->administrator_id),
                    'email' => $adminUser?->email,
                    'acted_at' => $row->acted_at?->toIso8601String(),
                    'acted_by' => $actor ? [
                        'name' => $actor->full_name,
                        'email' => $actor->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $requestedBy = $approvalRequest->requestedByUser;

        return [
            'request_id' => (int) $approvalRequest->id,
            'executed_at' => $approvalRequest->executed_at?->toIso8601String(),
            'required_approvals' => (int) $approvalRequest->required_approvals,
            'requested_by' => $requestedBy ? [
                'name' => $requestedBy->full_name,
                'email' => $requestedBy->email,
            ] : null,
            'approvers' => $approvers,
        ];
    }

    private function couponRulesForAssignUi(CouponAdminSettings $settings): array
    {
        $beneficiaryRules = CouponBeneficiaryApprovalRule::query()
            ->orderBy('min_beneficiaries')
            ->get()
            ->map(fn (CouponBeneficiaryApprovalRule $r) => [
                'min_beneficiaries' => $r->min_beneficiaries,
                'max_beneficiaries' => $r->max_beneficiaries,
                'required_approvals' => $r->required_approvals,
            ])
            ->values()
            ->all();

        return [
            'base_amount_cents' => $settings->base_amount_cents,
            'max_assignment_amount_cents' => $settings->max_assignment_amount_cents,
            'max_assignments_per_day' => $settings->max_assignments_per_day,
            'amount_threshold_cents' => $settings->amount_threshold_cents,
            'required_approvals_by_amount' => $settings->required_approvals_by_amount,
            'mass_campaign_threshold' => $settings->mass_campaign_threshold,
            'require_authorization' => $settings->require_authorization,
            'superadmin_bypass_approvals' => $settings->superadmin_bypass_approvals,
            'beneficiary_rules' => $beneficiaryRules,
        ];
    }

    private function couponIsLockedAfterApproval(Coupon $coupon): bool
    {
        return CouponApprovalRequest::query()
            ->where('type', 'assignment')
            ->where('status', 'executed')
            ->where('coupon_id', $coupon->id)
            ->where('payload->pre_approval_only', true)
            ->exists();
    }

    private function couponHasPendingPreApprovalLock(int $couponId): bool
    {
        return CouponApprovalRequest::query()
            ->where('type', 'assignment')
            ->where('coupon_id', $couponId)
            ->whereIn('status', ['pending', 'approved'])
            ->where('payload->pre_approval_only', true)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function defaultAuthorizerAdministratorIds(): array
    {
        return Administrator::role('autorizador')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function persistMasterCouponForAssign(Request $request, array $data, bool $relaxMaxAmountForPreApproval = false): Coupon
    {
        $adminSettings = CouponAdminSettings::singleton();

        if (! $relaxMaxAmountForPreApproval
            && $adminSettings->max_assignment_amount_cents !== null
            && (int) $data['amount_cents'] > $adminSettings->max_assignment_amount_cents) {
            throw new HttpResponseException(
                redirect()->back()->flashMessage(
                    'El monto supera el máximo configurado en las reglas de cupones.',
                    'error'
                )
            );
        }

        $pending = $adminSettings->require_authorization;
        if ($pending && trim((string) $adminSettings->authorization_email) === '') {
            throw new HttpResponseException(
                redirect()->back()->flashMessage(
                    'Configura el correo del autorizador en Reglas de cupones antes de exigir autorización.',
                    'error'
                )
            );
        }

        $this->couponService->assertAssignmentRules((int) $data['amount_cents'], ! $relaxMaxAmountForPreApproval);

        $coupon = Coupon::create([
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'amount_cents' => (int) $data['amount_cents'],
            'remaining_cents' => $pending ? 0 : (int) $data['amount_cents'],
            'max_beneficiaries' => $data['max_beneficiaries'] ?? null,
            'type' => 'balance',
            'is_active' => $pending ? false : ($data['is_active'] ?? true),
            'approval_status' => $pending ? CouponApprovalStatus::PendingAuthorization : CouponApprovalStatus::Active,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        if ($pending) {
            $plain = (string) random_int(100000, 999999);
            $coupon->authorization_code_hash = Hash::make($plain);
            $coupon->authorization_code_expires_at = $this->authorizationCodeExpiresAt();
            $coupon->save();

            $sent = $this->sendAuthorizationMailToArbitrator($coupon, $plain);
            if (! $sent['ok']) {
                throw new HttpResponseException(
                    redirect()->route('admin.coupons.index')->flashMessage(
                        'Cupón registrado, pero no se pudo enviar el correo: '.$sent['error'],
                        'error'
                    )
                );
            }
        }

        return $coupon->fresh();
    }

    private function finishCampaignAssignment(
        Request $request,
        Coupon $parent,
        User $user,
        bool $sendNotification,
        int $beneficiaryCountForRules
    ): RedirectResponse {
        $bypassAssignmentApprovals = $this->couponIsLockedAfterApproval($parent);

        $requiredApprovals = $this->couponService->resolveRequiredApprovals((int) $parent->amount_cents, $beneficiaryCountForRules);
        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        $settings = CouponAdminSettings::singleton();

        if (! $bypassAssignmentApprovals && $requiredApprovals > 0 && ! ($isSuperadmin && $settings->superadmin_bypass_approvals)) {
            $authorizerIds = collect($request->input('authorizer_ids', []))->map(fn ($v) => (int) $v)->filter()->values()->all();
            if (count($authorizerIds) === 0) {
                return redirect()->back()->flashMessage(
                    'Esta operación requiere aprobación según las reglas. Selecciona autorizadores.',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'assignment',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: min($requiredApprovals, count($authorizerIds)),
                authorizerAdministratorIds: $authorizerIds,
                payload: [
                    'email' => $user->email,
                    'coupon_id' => $parent->id,
                    'send_notification' => $sendNotification,
                ],
                couponId: (int) $parent->id
            );

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.assign')->flashMessage(
                "Se creó la solicitud de asignación #{$approvalRequest->id}. Se ejecutará al alcanzar las aprobaciones requeridas."
            );
        }

        try {
            $this->couponService->assignUserToCampaignCoupon(
                $user,
                $parent,
                $sendNotification,
                (int) $request->user()->id,
                enforceMaxAssignmentAmount: ! $bypassAssignmentApprovals
            );
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()->route('admin.coupons.show', $parent)->flashMessage('Beneficiario asignado al cupón.');
    }

    /**
     * @param  list<string>  $emails
     */
    private function finishBulkCampaignAssignment(
        Request $request,
        Coupon $parent,
        array $emails,
        bool $sendNotifications
    ): RedirectResponse {
        $bypassAssignmentApprovals = $this->couponIsLockedAfterApproval($parent);

        $requiredApprovals = $this->couponService->resolveRequiredApprovals((int) $parent->amount_cents, count($emails));
        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        $settings = CouponAdminSettings::singleton();

        if (! $bypassAssignmentApprovals && $requiredApprovals > 0 && ! ($isSuperadmin && $settings->superadmin_bypass_approvals)) {
            $authorizerIds = collect($request->input('authorizer_ids', []))->map(fn ($v) => (int) $v)->filter()->values()->all();
            if (count($authorizerIds) === 0) {
                return redirect()->back()->flashMessage(
                    'Esta carga masiva requiere aprobación según las reglas. Selecciona autorizadores.',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'assignment',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: min($requiredApprovals, count($authorizerIds)),
                authorizerAdministratorIds: $authorizerIds,
                payload: [
                    'emails' => $emails,
                    'coupon_id' => $parent->id,
                    'send_notification' => $sendNotifications,
                ],
                couponId: (int) $parent->id
            );

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.assign')->flashMessage(
                "Se creó la solicitud de asignación masiva #{$approvalRequest->id} (".count($emails).' correos).'
            );
        }

        $ok = 0;
        $errors = [];
        foreach ($emails as $email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $errors[] = "{$email}: usuario no registrado";

                continue;
            }
            try {
                $this->couponService->assignUserToCampaignCoupon(
                    $user,
                    $parent,
                    $sendNotifications,
                    (int) $request->user()->id,
                    enforceMaxAssignmentAmount: ! $bypassAssignmentApprovals
                );
                $ok++;
            } catch (\DomainException $e) {
                $errors[] = "{$email}: ".$e->getMessage();
            }
        }

        $msg = "Procesados {$ok} de ".count($emails).' registros.';
        if (count($errors) > 0) {
            $msg .= ' Algunos omitidos: '.implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $msg .= '…';
            }
        }

        return redirect()->route('admin.coupons.show', $parent)->flashMessage($msg);
    }

    /**
     * Resumen de la solicitud multi-firma (assignment) pendiente más reciente por cupón.
     *
     * @param  list<int>  $couponIds
     * @return array<int, array{request_id: int, current: int, required: int, remaining: int, pre_approval_only: bool}>
     */
    private function couponIndexAssignmentApprovalSummaries(array $couponIds): array
    {
        if ($couponIds === []) {
            return [];
        }

        $grouped = CouponApprovalRequest::query()
            ->whereIn('coupon_id', $couponIds)
            ->where('status', 'pending')
            ->where('type', 'assignment')
            ->orderByDesc('id')
            ->get()
            ->groupBy('coupon_id');

        $out = [];
        foreach ($grouped as $couponId => $rows) {
            /** @var CouponApprovalRequest $r */
            $r = $rows->first();
            $required = (int) $r->required_approvals;
            $current = (int) $r->current_approvals;
            $out[(int) $couponId] = [
                'request_id' => (int) $r->id,
                'current' => $current,
                'required' => $required,
                'remaining' => max(0, $required - $current),
                'pre_approval_only' => (bool) ($r->payload['pre_approval_only'] ?? false),
            ];
        }

        return $out;
    }

    private function couponIndexQuery(Request $request)
    {
        $q = Coupon::query()
            ->rootCoupons()
            ->with([
                'couponUsers' => function ($query) {
                    $query->orderBy('assigned_at');
                },
                'couponUsers.user',
                'createdByUser:id,name,paternal_lastname,maternal_lastname,email',
            ])
            ->withCount(['couponUsers', 'childCoupons']);

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $q->where(function ($sub) use ($search) {
                $sub->where('code', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('couponUsers.user', function ($uq) use ($search) {
                        $uq->where('email', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('childCoupons.couponUsers.user', function ($uq) use ($search) {
                        $uq->where('email', 'like', '%'.$search.'%');
                    });
            });
        }

        $userEmail = $request->string('user_email')->trim()->toString();
        if ($userEmail !== '') {
            $q->where(function ($sub) use ($userEmail) {
                $sub->whereHas('couponUsers.user', function ($uq) use ($userEmail) {
                    $uq->where('email', 'like', '%'.$userEmail.'%');
                })->orWhereHas('childCoupons.couponUsers.user', function ($uq) use ($userEmail) {
                    $uq->where('email', 'like', '%'.$userEmail.'%');
                });
            });
        }

        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $usage = $request->input('usage', 'all');
        match ($usage) {
            'pending' => $q->where('approval_status', CouponApprovalStatus::PendingAuthorization),
            'unused' => $q->where(function ($sub) {
                $sub->whereHas('couponUsers', fn ($cq) => $cq->whereNull('used_at'))
                    ->orWhereHas('childCoupons.couponUsers', fn ($cq) => $cq->whereNull('used_at'));
            }),
            'used' => $q->where(function ($sub) {
                $sub->whereHas('couponUsers', fn ($cq) => $cq->whereNotNull('used_at'))
                    ->orWhereHas('childCoupons.couponUsers', fn ($cq) => $cq->whereNotNull('used_at'));
            }),
            'unassigned' => $q->whereDoesntHave('childCoupons')->whereDoesntHave('couponUsers'),
            default => null,
        };

        return $q;
    }

    private function authorizationCodeExpiresAt(): Carbon
    {
        return now()->addMinutes(5);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function sendAuthorizationMailToArbitrator(Coupon $coupon, string $plainCode): array
    {
        $email = trim((string) CouponAdminSettings::singleton()->authorization_email);
        if ($email === '') {
            return ['ok' => false, 'error' => 'No hay correo del autorizador configurado.'];
        }

        try {
            Mail::to($email)->send(new CouponAuthorizationRequestedMail($coupon, $plainCode));
        } catch (\Throwable $e) {
            Log::error('Envío de correo de autorización de cupón falló', [
                'coupon_id' => $coupon->id,
                'to' => $email,
                'exception' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    private function mailDriverNoticeForSuccessMessage(): string
    {
        return match (config('mail.default')) {
            'log' => ' Nota: con MAIL_MAILER=log el mensaje se guarda en storage/logs/laravel.log (no llega a un buzón real). Configura SMTP en .env para envío real.',
            'array' => ' Nota: MAIL_MAILER=array no entrega correos reales (solo pruebas).',
            default => '',
        };
    }

    private function mailSetupHintForCouponShow(): ?string
    {
        return match (config('mail.default')) {
            'log' => 'Este entorno usa MAIL_MAILER=log: el “correo” se escribe en storage/logs/laravel.log, no en Gmail ni otros buzones. Para recibir el código de verdad, configura un transporte real (p. ej. SMTP: MAIL_MAILER=smtp, MAIL_HOST, etc.).',
            'array' => 'MAIL_MAILER=array no envía correos a buzones; solo sirve para pruebas en memoria.',
            default => null,
        };
    }

    private function sendApprovalRequestMails(CouponApprovalRequest $approvalRequest): void
    {
        $approvalRequest->loadMissing('authorizers.administrator.user');

        foreach ($approvalRequest->authorizers as $authorizer) {
            $email = $authorizer->administrator?->user?->email;
            if (! $email) {
                continue;
            }

            Mail::to($email)->send(new CouponApprovalRequestMail(
                $approvalRequest,
                route('admin.coupons.logs', absolute: true)
            ));
        }
    }
}
