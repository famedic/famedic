<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CouponApprovalStatus;
use App\Http\Controllers\Controller;
use App\Enums\CouponBeneficiarySource;
use App\Imports\CouponBeneficiariesRowsImport;
use App\Services\CouponBeneficiaryService;
use App\Imports\CouponCampaignEmailsImport;
use App\Imports\CouponsExcelImport;
use App\Mail\CouponAuthorizationRequestedMail;
use App\Mail\CouponApprovalRequestMail;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\CouponAuditLog;
use App\Models\CouponAmountApprovalRule;
use App\Models\CouponBeneficiaryApprovalRule;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\CouponBeneficiary;
use App\Models\CouponConcept;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\User;
use App\Services\CouponService;
use App\Services\CouponConceptService;
use App\Services\CouponEligibilityFormService;
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
        private CouponService $couponService,
        private CouponBeneficiaryService $couponBeneficiaryService,
        private CouponConceptService $couponConceptService,
        private CouponEligibilityFormService $couponEligibilityFormService,
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
        $allowedTabs = ['limits', 'approval', 'authorizers', 'history', 'concepts'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'limits';
        }

        $currentAdministratorId = $request->user()->administrator?->hasRole('autorizador')
            ? (int) $request->user()->administrator->id
            : null;

        return Inertia::render('Admin/Coupons/Settings', [
            'settings' => CouponAdminSettings::singleton(),
            'amountRules' => CouponAmountApprovalRule::query()
                ->orderBy('min_amount_cents')
                ->get()
                ->map(fn (CouponAmountApprovalRule $r) => [
                    'min_amount_cents' => (int) $r->min_amount_cents,
                    'max_amount_cents' => $r->max_amount_cents !== null ? (int) $r->max_amount_cents : null,
                    'required_approvals' => (int) $r->required_approvals,
                ])
                ->values()
                ->all(),
            'beneficiaryRules' => CouponBeneficiaryApprovalRule::query()
                ->orderBy('min_beneficiaries')
                ->get()
                ->map(fn (CouponBeneficiaryApprovalRule $r) => [
                    'min_beneficiaries' => (int) $r->min_beneficiaries,
                    'max_beneficiaries' => $r->max_beneficiaries !== null ? (int) $r->max_beneficiaries : null,
                    'required_approvals' => (int) $r->required_approvals,
                ])
                ->values()
                ->all(),
            'authorizers' => $authorizers,
            'initialTab' => $tab,
            'settingsApprovalHistory' => $this->settingsApprovalHistoryForUi($currentAdministratorId),
            'concepts' => CouponConcept::query()
                ->orderBy('title')
                ->get(['id', 'title', 'description'])
                ->map(fn (CouponConcept $c) => [
                    'id' => (int) $c->id,
                    'title' => $c->title,
                    'description' => $c->description,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Historial de solicitudes de cambio en reglas (tipo settings): solicitante, firmas y estado.
     *
     * @return list<array<string, mixed>>
     */
    private function settingsApprovalHistoryForUi(?int $currentAdministratorId = null): array
    {
        return CouponApprovalRequest::query()
            ->where('type', 'settings')
            ->with([
                'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
                'rejectedByUser:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers' => fn ($q) => $q->orderBy('id')->with([
                    'administrator.user:id,name,paternal_lastname,maternal_lastname,email',
                    'actedByUser:id,name,paternal_lastname,maternal_lastname,email',
                ]),
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (CouponApprovalRequest $r) use ($currentAdministratorId) {
                $approvers = $r->authorizers
                    ->filter(fn (CouponApprovalRequestAuthorizer $row) => $row->status === 'approved')
                    ->map(function (CouponApprovalRequestAuthorizer $row) {
                        $actor = $row->actedByUser;
                        $fallback = $row->administrator?->user;

                        return [
                            'name' => $actor?->full_name ?: ($fallback?->full_name ?: '—'),
                            'email' => $actor?->email ?? $fallback?->email,
                            'acted_at' => $row->acted_at?->toIso8601String(),
                        ];
                    })
                    ->values()
                    ->all();

                $canApprove = $currentAdministratorId !== null
                    && $r->status === 'pending'
                    && $r->authorizers->contains(fn (CouponApprovalRequestAuthorizer $row) => (int) $row->administrator_id === $currentAdministratorId
                        && $row->status === 'pending');

                return [
                    'id' => $r->id,
                    'status' => $r->status,
                    'required_approvals' => (int) $r->required_approvals,
                    'current_approvals' => (int) $r->current_approvals,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'executed_at' => $r->executed_at?->toIso8601String(),
                    'rejected_at' => $r->rejected_at?->toIso8601String(),
                    'requested_by' => [
                        'name' => $r->requestedByUser?->full_name ?: '—',
                        'email' => $r->requestedByUser?->email,
                    ],
                    'rejected_by' => $r->rejectedByUser ? [
                        'name' => $r->rejectedByUser->full_name ?: '—',
                        'email' => $r->rejectedByUser->email,
                    ] : null,
                    'approvers' => $approvers,
                    'can_approve' => $canApprove,
                    'before_state' => $r->before_state,
                    'after_state' => $r->after_state,
                ];
            })
            ->values()
            ->all();
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
            'amount_rules' => ['nullable', 'array'],
            'amount_rules.*.min_amount_mxn' => ['required_with:amount_rules', 'numeric', 'min:0'],
            'amount_rules.*.max_amount_mxn' => ['nullable', 'numeric', 'min:0'],
            'amount_rules.*.required_approvals' => ['required_with:amount_rules', 'integer', 'min:0'],
            'mass_campaign_threshold' => ['nullable', 'integer', 'min:1'],
            'superadmin_bypass_approvals' => ['boolean'],
            'beneficiary_rules' => ['nullable', 'array'],
            'beneficiary_rules.*.min' => ['required_with:beneficiary_rules', 'integer', 'min:1'],
            'beneficiary_rules.*.max' => ['nullable', 'integer', 'min:1'],
            'beneficiary_rules.*.required_approvals' => ['required_with:beneficiary_rules', 'integer', 'min:0'],
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
            'amount_rules' => CouponAmountApprovalRule::query()->orderBy('min_amount_cents')->get()->toArray(),
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
        $after['amount_threshold_cents'] = null;
        $after['required_approvals_by_amount'] = 0;
        $after['mass_campaign_threshold'] = $data['mass_campaign_threshold'] ?? null;
        $after['superadmin_bypass_approvals'] = $data['superadmin_bypass_approvals'] ?? true;
        $after['amount_rules'] = collect($data['amount_rules'] ?? [])
            ->map(function ($rule) {
                $min = isset($rule['min_amount_mxn']) && $rule['min_amount_mxn'] !== null && $rule['min_amount_mxn'] !== ''
                    ? (int) round((float) $rule['min_amount_mxn'] * 100)
                    : 0;
                $max = isset($rule['max_amount_mxn']) && $rule['max_amount_mxn'] !== null && $rule['max_amount_mxn'] !== ''
                    ? (int) round((float) $rule['max_amount_mxn'] * 100)
                    : null;

                return [
                    'min_amount_cents' => $min,
                    'max_amount_cents' => $max,
                    'required_approvals' => (int) $rule['required_approvals'],
                ];
            })
            ->values()
            ->all();
        $after['beneficiary_rules'] = collect($data['beneficiary_rules'] ?? [])
            ->map(fn ($rule) => [
                'min_beneficiaries' => (int) $rule['min'],
                'max_beneficiaries' => isset($rule['max']) && $rule['max'] !== '' ? (int) $rule['max'] : null,
                'required_approvals' => (int) $rule['required_approvals'],
            ])->values()->all();

        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        if (! $isSuperadmin) {
            // Envío a TODOS los autorizadores y mínimo 2 aprobaciones.
            $authorizerIds = $this->defaultAuthorizerAdministratorIds();
            if (count($authorizerIds) < 2) {
                return redirect()->back()->flashMessage(
                    'No hay suficientes autorizadores configurados (se requieren al menos 2).',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'settings',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: 2,
                authorizerAdministratorIds: $authorizerIds,
                beforeState: $before,
                afterState: $after
            );

            Log::info('Solicitud de aprobación de configuración de cupones creada', [
                'approval_request_id' => $approvalRequest->id,
                'requested_by_user_id' => $request->user()->id,
                'required_approvals' => 2,
                'authorizer_administrator_ids' => $authorizerIds,
            ]);

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.settings')->flashMessage(
                "Se creó la solicitud #{$approvalRequest->id}. Los cambios se aplicarán al completar las aprobaciones requeridas."
            );
        }

        $settings->fill($after);
        $settings->save();

        CouponAmountApprovalRule::query()->delete();
        foreach ($after['amount_rules'] as $rule) {
            CouponAmountApprovalRule::create($rule);
        }

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

        $data = $request->validate(array_merge([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ], $this->couponConceptService->validationRules(), $this->couponEligibilityFormService->validationRules()));

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

        $conceptPayload = $this->couponConceptService->resolveConceptPayload($data);
        $eligibilityAttributes = $this->couponEligibilityFormService->resolveAttributes($data);

        $coupon = Coupon::create(array_merge([
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'coupon_concept_id' => $conceptPayload['coupon_concept_id'],
            'concept_other' => $conceptPayload['concept_other'],
            'amount_cents' => $data['amount_cents'],
            'remaining_cents' => $pending ? 0 : $data['amount_cents'],
            'max_beneficiaries' => $data['max_beneficiaries'] ?? null,
            'type' => 'balance',
            'is_active' => $pending ? false : ($data['is_active'] ?? true),
            'approval_status' => $pending ? CouponApprovalStatus::PendingAuthorization : CouponApprovalStatus::Active,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ], $eligibilityAttributes));

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
            'concept:id,title,description',
            'transactions.reversedByUser:id,name,paternal_lastname,maternal_lastname,email',
            'couponUsers' => fn ($q) => $q->orderBy('assigned_at'),
            'couponUsers.user:id,name,paternal_lastname,maternal_lastname,email',
            'childCoupons.couponUsers.user:id,name,paternal_lastname,maternal_lastname,email',
            'childCoupons.transactions.reversedByUser:id,name,paternal_lastname,maternal_lastname,email',
            'beneficiaries' => fn ($q) => $q->whereNull('cancelled_at')->orderBy('id'),
        ]);

        $beneficiaryRows = [];
        $beneficiariesByChildId = $coupon->beneficiaries
            ->filter(fn ($b) => $b->child_coupon_id !== null)
            ->keyBy('child_coupon_id');

        foreach ($coupon->childCoupons->sortBy('id') as $child) {
            $assignment = $child->couponUsers->first();
            $user = $assignment?->user;
            $tx = $child->transactions->first();
            $trackedBeneficiary = $beneficiariesByChildId->get($child->id);
            $beneficiaryRows[] = [
                'assignment_id' => $assignment?->id,
                'coupon_id' => $child->id,
                'user' => $user,
                'assigned_at' => $assignment?->assigned_at,
                'claimed_at' => $trackedBeneficiary?->claimed_at?->toIso8601String(),
                'used_at' => $assignment?->used_at,
                'remaining_cents' => $child->remaining_cents,
                'valid_from' => $child->valid_from?->toIso8601String(),
                'expires_at' => $child->expires_at?->toIso8601String(),
                'formatted_min_purchase' => $child->formatted_min_purchase,
                'validity_status' => $child->validity_status,
                'transaction' => $this->couponTransactionForBeneficiaryUi($tx),
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
                'valid_from' => $coupon->valid_from?->toIso8601String(),
                'expires_at' => $coupon->expires_at?->toIso8601String(),
                'formatted_min_purchase' => $coupon->formatted_min_purchase,
                'validity_status' => $coupon->validity_status,
                'transaction' => $this->couponTransactionForBeneficiaryUi($tx),
            ];
        }

        foreach ($coupon->beneficiaries as $pending) {
            if ($pending->status->value !== 'pending_user') {
                continue;
            }

            $beneficiaryRows[] = [
                'assignment_id' => null,
                'coupon_id' => null,
                'beneficiary_id' => $pending->id,
                'user' => null,
                'pending_email' => $pending->email,
                'pending_name' => trim(implode(' ', array_filter([
                    $pending->first_name,
                    $pending->paternal_lastname,
                    $pending->maternal_lastname,
                ]))) ?: null,
                'assigned_at' => $pending->created_at,
                'used_at' => null,
                'remaining_cents' => $coupon->amount_cents,
                'valid_from' => $coupon->valid_from?->toIso8601String(),
                'expires_at' => $coupon->expires_at?->toIso8601String(),
                'formatted_min_purchase' => $coupon->formatted_min_purchase,
                'validity_status' => $coupon->validity_status,
                'transaction' => null,
                'is_pending_user' => true,
                'invitation_sent_at' => $pending->invitation_sent_at?->toIso8601String(),
                'last_invitation_sent_at' => $pending->last_invitation_sent_at?->toIso8601String(),
                'invitation_count' => (int) $pending->invitation_count,
                'can_resend_invitation' => $pending->canResendInvitation(),
                'resend_invitation_available_at' => $pending->resendInvitationAvailableAt()?->toIso8601String(),
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

    /**
     * @return array<string, mixed>|null
     */
    private function couponTransactionForBeneficiaryUi(?\App\Models\CouponTransaction $tx): ?array
    {
        if ($tx === null) {
            return null;
        }

        $reversedBy = $tx->reversedByUser;

        return [
            'amount_used_cents' => (int) $tx->amount_used_cents,
            'purchase_type' => $tx->purchase_type->value,
            'purchase_url' => $this->purchaseAdminUrl($tx),
            'is_reversed' => $tx->isReversed(),
            'reversed_at' => $tx->reversed_at?->toIso8601String(),
            'reversal_reason' => $tx->reversal_reason,
            'reversed_by_user' => $reversedBy ? [
                'id' => $reversedBy->id,
                'full_name' => $reversedBy->full_name,
                'email' => $reversedBy->email,
            ] : null,
        ];
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

        $data = $request->validate(array_merge([
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ], $this->couponEligibilityFormService->validationRules()));

        $eligibilityAttributes = $this->couponEligibilityFormService->resolveAttributes($data);

        $coupon->code = $data['code'] ?? null;
        $coupon->description = $data['description'] ?? null;
        $coupon->max_beneficiaries = $data['max_beneficiaries'] ?? null;
        $coupon->is_active = $data['is_active'] ?? $coupon->is_active;
        $coupon->fill($eligibilityAttributes);
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
            'concepts' => CouponConcept::query()
                ->orderBy('title')
                ->get(['id', 'title', 'description'])
                ->map(fn (CouponConcept $c) => [
                    'id' => (int) $c->id,
                    'title' => $c->title,
                    'description' => $c->description,
                ])
                ->values()
                ->all(),
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

        $import = new CouponBeneficiariesRowsImport;
        Excel::import($import, $request->file('file'));
        $inputRows = $import->getRows();

        if ($inputRows === []) {
            return response()->json([
                'message' => 'No se encontraron filas válidas. Usa columnas email/correo (obligatorio) y nombre/apellidos opcionales.',
                'rows' => [],
            ], 422);
        }

        $maxRows = 5000;
        $deduped = [];
        $seen = [];
        foreach ($inputRows as $row) {
            $normalized = strtolower(trim((string) ($row['email'] ?? '')));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $deduped[] = array_merge($row, ['email' => $normalized]);
            if (count($deduped) > $maxRows) {
                return response()->json([
                    'message' => "El archivo supera el máximo de {$maxRows} correos distintos.",
                    'rows' => [],
                ], 422);
            }
        }

        $emails = array_column($deduped, 'email');
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
        foreach ($deduped as $row) {
            $email = (string) $row['email'];
            $user = $byLower[$email] ?? null;
            $firstName = $row['first_name'] ?? null;
            $paternalLastname = $row['paternal_lastname'] ?? null;
            $maternalLastname = $row['maternal_lastname'] ?? null;

            if ($user !== null) {
                if ($firstName === null && $paternalLastname === null && $maternalLastname === null) {
                    $firstName = $user->name ?: null;
                    $paternalLastname = $user->paternal_lastname ?: null;
                    $maternalLastname = $user->maternal_lastname ?: null;
                }
            }

            $rows[] = [
                'email' => $email,
                'first_name' => $firstName,
                'paternal_lastname' => $paternalLastname,
                'maternal_lastname' => $maternalLastname,
                'status' => $user !== null ? 'valid_registered_user' : 'valid_pending_user',
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

    public function previewBeneficiaries(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('create', Coupon::class);
        $this->assertAssignableMasterCoupon($coupon);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:5000'],
            'rows.*.email' => ['required', 'string', 'max:255'],
            'rows.*.first_name' => ['nullable', 'string', 'max:255'],
            'rows.*.paternal_lastname' => ['nullable', 'string', 'max:255'],
            'rows.*.maternal_lastname' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = $this->couponBeneficiaryService->validateInputRows($data['rows']);
        $preview = $this->couponBeneficiaryService->previewRows($coupon, $rows);

        CouponAuditLog::create([
            'type' => 'assignment',
            'action' => 'coupon_beneficiaries_previewed',
            'status' => 'completed',
            'actor_user_id' => $request->user()->id,
            'coupon_id' => $coupon->id,
            'context' => [
                'parent_coupon_id' => $coupon->id,
                'row_count' => count($preview['rows']),
                'summary' => $preview['summary'],
            ],
        ]);

        return response()->json($preview);
    }

    public function previewBeneficiariesFile(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('create', Coupon::class);
        $this->assertAssignableMasterCoupon($coupon);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new CouponBeneficiariesRowsImport;
        Excel::import($import, $request->file('file'));
        $rows = $import->getRows();

        if ($rows === []) {
            return response()->json([
                'message' => 'No se encontraron filas válidas. Usa columnas email/correo (obligatorio) y nombre/apellidos opcionales.',
                'rows' => [],
                'summary' => [],
            ], 422);
        }

        $preview = $this->couponBeneficiaryService->previewRows($coupon, $rows);

        CouponAuditLog::create([
            'type' => 'assignment',
            'action' => 'coupon_beneficiaries_previewed',
            'status' => 'completed',
            'actor_user_id' => $request->user()->id,
            'coupon_id' => $coupon->id,
            'context' => [
                'parent_coupon_id' => $coupon->id,
                'source' => 'excel',
                'row_count' => count($preview['rows']),
                'summary' => $preview['summary'],
            ],
        ]);

        return response()->json($preview);
    }

    public function resendBeneficiaryInvitation(Request $request, Coupon $coupon, CouponBeneficiary $beneficiary): RedirectResponse
    {
        $this->authorize('create', Coupon::class);
        $this->assertAssignableMasterCoupon($coupon);

        if ((int) $beneficiary->parent_coupon_id !== (int) $coupon->id) {
            abort(404);
        }

        try {
            $this->couponBeneficiaryService->resendPendingInvitation($beneficiary, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()->back()->flashMessage('Invitación reenviada correctamente.');
    }

    public function confirmBeneficiaries(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('create', Coupon::class);
        $this->assertAssignableMasterCoupon($coupon);

        if ($this->couponHasPendingPreApprovalLock((int) $coupon->id)) {
            return redirect()->back()->flashMessage(
                'Este cupón está bloqueado para asignaciones hasta completar las aprobaciones requeridas.',
                'error'
            );
        }

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:5000'],
            'rows.*.email' => ['required', 'string', 'max:255'],
            'rows.*.first_name' => ['nullable', 'string', 'max:255'],
            'rows.*.paternal_lastname' => ['nullable', 'string', 'max:255'],
            'rows.*.maternal_lastname' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['manual', 'excel'])],
            'send_notifications' => ['boolean'],
            'authorizer_ids' => ['nullable', 'array'],
            'authorizer_ids.*' => ['integer', Rule::exists('administrators', 'id')],
        ]);

        $rows = $this->couponBeneficiaryService->validateInputRows($data['rows']);
        $source = CouponBeneficiarySource::from($data['source'] ?? 'manual');
        $sendNotifications = $data['send_notifications'] ?? true;

        return $this->finishBeneficiaryAssignment(
            $request,
            $coupon,
            $rows,
            $source,
            $sendNotifications
        );
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $assignmentMode = (string) $request->input('assignment_mode');

        $data = $request->validate(array_merge([
            'coupon_mode' => ['required', Rule::in(['existing', 'new'])],
            'assignment_mode' => ['required', Rule::in(['none', 'individual', 'bulk'])],
            'coupon_id' => ['required_if:coupon_mode,existing', 'nullable', 'integer', Rule::exists('coupons', 'id')],
            'file' => [
                'nullable',
                'file',
                'mimes:xlsx,xls,csv',
                Rule::requiredIf(fn () => $request->input('assignment_mode') === 'bulk'
                    && empty($request->input('bulk_emails'))),
            ],
            'bulk_emails' => [
                Rule::requiredIf(fn () => $assignmentMode === 'individual' && empty($request->input('beneficiary_rows'))),
                'nullable',
                'array',
                Rule::when($assignmentMode === 'individual' && empty($request->input('beneficiary_rows')), ['min:1']),
                'max:5000',
            ],
            'bulk_emails.*' => array_values(array_filter([
                'email',
                'max:255',
                $assignmentMode === 'individual' && empty($request->input('beneficiary_rows'))
                    ? Rule::exists('users', 'email')
                    : null,
            ])),
            'send_notification' => ['boolean'],
            'send_notifications' => ['boolean'],
            'amount_cents' => ['required_if:coupon_mode,new', 'nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_beneficiaries' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'authorizer_ids' => ['nullable', 'array'],
            'authorizer_ids.*' => ['integer', Rule::exists('administrators', 'id')],
            'beneficiary_rows' => ['nullable', 'array', 'max:5000'],
            'beneficiary_rows.*.email' => ['required_with:beneficiary_rows', 'string', 'max:255'],
            'beneficiary_rows.*.first_name' => ['nullable', 'string', 'max:255'],
            'beneficiary_rows.*.paternal_lastname' => ['nullable', 'string', 'max:255'],
            'beneficiary_rows.*.maternal_lastname' => ['nullable', 'string', 'max:255'],
            'beneficiary_source' => ['nullable', Rule::in(['manual', 'excel'])],
        ], $this->couponConceptService->validationRules(), $this->couponEligibilityFormService->validationRules()));

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
            $relaxMaxForPreApproval = $requiredPreApprovalsDraft > 0
                && ! ($isSuperadminDraft && $settingsDraft->superadmin_bypass_approvals);

            $parent = $this->persistMasterCouponForAssign($request, $data, $relaxMaxForPreApproval);
            if ($parent->approval_status === CouponApprovalStatus::PendingAuthorization
                && $data['assignment_mode'] === 'none') {
                return redirect()->route('admin.coupons.show', $parent)->flashMessage(
                    'Cupón creado. Completa la autorización por correo (código) o espera las firmas si definiste asignaciones en el mismo envío.'
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

        if (in_array($data['assignment_mode'], ['individual', 'bulk'], true)
            && ! empty($data['beneficiary_rows'])) {
            $rows = $this->couponBeneficiaryService->validateInputRows($data['beneficiary_rows']);
            $source = CouponBeneficiarySource::from(
                $data['beneficiary_source'] ?? ($data['assignment_mode'] === 'bulk' ? 'excel' : 'manual')
            );
            $sendNotifications = $data['assignment_mode'] === 'individual' ? $sendForIndividual : $sendForBulk;

            return $this->finishBeneficiaryAssignment(
                $request,
                $parent,
                $rows,
                $source,
                $sendNotifications
            );
        }

        if ($data['assignment_mode'] === 'individual') {
            $emails = collect($data['bulk_emails'] ?? [])
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($emails === []) {
                return redirect()->back()->flashMessage(
                    'Indica al menos un correo de usuario registrado en la lista manual.',
                    'error'
                );
            }

            $assigned = $this->couponBeneficiaryService->countActiveBeneficiarySlots($parent);
            if ($parent->max_beneficiaries !== null && $assigned + count($emails) > (int) $parent->max_beneficiaries) {
                $remaining = max(0, (int) $parent->max_beneficiaries - $assigned);

                return redirect()->back()->flashMessage(
                    'Con este cupón solo puedes asignar hasta '.$remaining.' beneficiario(s) más (ya hay '.$assigned.' de un máximo de '.(int) $parent->max_beneficiaries.'). En la lista hay '.count($emails).' correo(s) distinto(s).',
                    'error'
                );
            }

            return $this->finishBulkCampaignAssignment(
                $request,
                $parent,
                $emails,
                $sendForIndividual
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
                    $amountRules = $after['amount_rules'] ?? [];
                $rules = $after['beneficiary_rules'] ?? [];
                    unset($after['beneficiary_rules'], $after['amount_rules']);
                $settings->fill($after);
                $settings->save();

                    CouponAmountApprovalRule::query()->delete();
                    foreach ($amountRules as $r) {
                        if (! is_array($r)) {
                            continue;
                        }
                        CouponAmountApprovalRule::create([
                            'min_amount_cents' => (int) ($r['min_amount_cents'] ?? 0),
                            'max_amount_cents' => isset($r['max_amount_cents']) ? (is_null($r['max_amount_cents']) ? null : (int) $r['max_amount_cents']) : null,
                            'required_approvals' => (int) ($r['required_approvals'] ?? 0),
                        ]);
                    }

                CouponBeneficiaryApprovalRule::query()->delete();
                foreach ($rules as $rule) {
                    CouponBeneficiaryApprovalRule::create($rule);
                }
            } elseif ($approvalRequest->type === 'assignment') {
                $payload = $approvalRequest->payload ?? [];
                $coupon = Coupon::query()->findOrFail((int) ($payload['coupon_id'] ?? 0));
                $sendNotification = (bool) ($payload['send_notification'] ?? true);
                $requestedBy = (int) $approvalRequest->requested_by_user_id;

                if (! empty($payload['pre_approval_only'])
                    && empty($payload['emails'])
                    && empty($payload['beneficiary_rows'])) {
                    $approvalRequest->status = 'executed';
                    $approvalRequest->executed_at = now();
                    $approvalRequest->save();

                    return redirect()->back()->flashMessage('Solicitud aprobada.');
                }

                if (($payload['activate_parent_on_execute'] ?? false) === true) {
                    $this->activateMasterCouponAsApprover($coupon, (int) $request->user()->id);
                    $coupon = $coupon->fresh();
                }

                if (! empty($payload['beneficiary_rows']) && is_array($payload['beneficiary_rows'])) {
                    $this->couponBeneficiaryService->confirmRows(
                        $coupon,
                        $payload['beneficiary_rows'],
                        $request->user(),
                        CouponBeneficiarySource::from($payload['source'] ?? 'manual'),
                        $sendNotification,
                        enforceMaxAssignmentAmount: false
                    );
                } elseif (! empty($payload['emails']) && is_array($payload['emails'])) {
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
     * @return array{
     *     is_authorizer: bool,
     *     pending_assignment_approvals_count: int,
     *     pending_my_action_coupon_ids: list<int>,
     *     pending_settings_approvals_count: int,
     *     pending_settings_requests: list<array<string, mixed>>,
     *     pending_assignment_cards: list<array<string, mixed>>
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
                'pending_settings_requests' => [],
                'pending_assignment_cards' => [],
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

        $pending_settings_requests = $this->pendingSettingsApprovalRequestsSummaryForAuthorizer($adminId);
        $pending_settings_approvals_count = count($pending_settings_requests);

        $pending_assignment_cards = $pending_assignment_approvals_count > 0
            ? $this->pendingAssignmentApprovalCardsForAuthorizer($pendingAssignmentIds)
            : [];

        return [
            'is_authorizer' => true,
            'pending_assignment_approvals_count' => $pending_assignment_approvals_count,
            'pending_my_action_coupon_ids' => $pending_my_action_coupon_ids,
            'pending_settings_approvals_count' => $pending_settings_approvals_count,
            'pending_settings_requests' => $pending_settings_requests,
            'pending_assignment_cards' => $pending_assignment_cards,
        ];
    }

    /**
     * Resumen legible para autorizadores: cupón, solicitante y beneficiarios previstos.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $pendingAssignmentRequestIds
     * @return list<array<string, mixed>>
     */
    private function pendingAssignmentApprovalCardsForAuthorizer($pendingAssignmentRequestIds): array
    {
        if ($pendingAssignmentRequestIds->isEmpty()) {
            return [];
        }

        return CouponApprovalRequest::query()
            ->whereIn('id', $pendingAssignmentRequestIds)
            ->with([
                'coupon:id,code,description,amount_cents,max_beneficiaries,approval_status,is_active',
                'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function (CouponApprovalRequest $req) {
                $payload = is_array($req->payload) ? $req->payload : [];
                $emails = [];
                if (! empty($payload['emails']) && is_array($payload['emails'])) {
                    $emails = array_values(array_unique(array_filter(array_map(
                        fn ($e) => strtolower(trim((string) $e)),
                        $payload['emails']
                    ))));
                } elseif (! empty($payload['email'])) {
                    $one = strtolower(trim((string) $payload['email']));
                    if ($one !== '') {
                        $emails = [$one];
                    }
                }

                $beneficiaries = [];
                if ($emails !== []) {
                    $slice = array_slice($emails, 0, 50);
                    $users = User::query()
                        ->whereIn('email', $slice)
                        ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'email']);
                    $byEmail = $users->keyBy(fn (User $u) => strtolower((string) $u->email));
                    foreach ($slice as $mail) {
                        $u = $byEmail->get($mail);
                        $beneficiaries[] = [
                            'email' => $mail,
                            'name' => $u ? ($u->full_name ?: trim(implode(' ', array_filter([
                                $u->name,
                                $u->paternal_lastname,
                                $u->maternal_lastname,
                            ])))) : null,
                        ];
                    }
                }

                $coupon = $req->coupon;
                $approvalStatus = $coupon?->approval_status;
                $approvalStatusValue = $approvalStatus instanceof \BackedEnum
                    ? $approvalStatus->value
                    : (string) ($approvalStatus ?? '');

                return [
                    'id' => (int) $req->id,
                    'coupon_id' => (int) $req->coupon_id,
                    'required_approvals' => (int) $req->required_approvals,
                    'current_approvals' => (int) $req->current_approvals,
                    'pre_approval_only' => (bool) ($payload['pre_approval_only'] ?? false),
                    'activate_parent_on_execute' => (bool) ($payload['activate_parent_on_execute'] ?? false),
                    'send_notification' => (bool) ($payload['send_notification'] ?? true),
                    'requested_by' => [
                        'name' => $req->requestedByUser?->full_name ?: '—',
                        'email' => $req->requestedByUser?->email,
                    ],
                    'coupon' => $coupon ? [
                        'code' => $coupon->code,
                        'description' => $coupon->description,
                        'amount_cents' => (int) $coupon->amount_cents,
                        'max_beneficiaries' => $coupon->max_beneficiaries,
                        'approval_status' => $approvalStatusValue,
                        'is_active' => (bool) $coupon->is_active,
                    ] : null,
                    'beneficiary_emails_total' => count($emails),
                    'beneficiaries_preview' => array_slice($beneficiaries, 0, 25),
                    'beneficiaries_truncated' => max(0, count($emails) - 25),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Solicitudes de cambio en reglas (settings) donde este autorizador aún no ha firmado.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingSettingsApprovalRequestsSummaryForAuthorizer(int $administratorId): array
    {
        return CouponApprovalRequest::query()
            ->where('status', 'pending')
            ->where('type', 'settings')
            ->whereHas('authorizers', fn ($q) => $q->where('administrator_id', $administratorId)->where('status', 'pending'))
            ->with(['requestedByUser:id,name,paternal_lastname,maternal_lastname,email'])
            ->orderByDesc('id')
            ->get()
            ->map(function (CouponApprovalRequest $req) {
                $after = is_array($req->after_state) ? $req->after_state : [];

                return [
                    'id' => $req->id,
                    'required_approvals' => (int) $req->required_approvals,
                    'current_approvals' => (int) $req->current_approvals,
                    'created_at' => $req->created_at?->toIso8601String(),
                    'requested_by' => [
                        'name' => $req->requestedByUser?->full_name ?: '—',
                        'email' => $req->requestedByUser?->email,
                    ],
                    'amount_rules' => $this->mapAmountApprovalRulesPreviewForAuthorizer($after['amount_rules'] ?? null),
                    'beneficiary_rules' => $this->mapBeneficiaryApprovalRulesPreviewForAuthorizer($after['beneficiary_rules'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $rules
     * @return list<array{min_mxn: float, max_mxn: ?float, required_approvals: int}>
     */
    private function mapAmountApprovalRulesPreviewForAuthorizer(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }
        $out = [];
        foreach ($rules as $row) {
            if (! is_array($row)) {
                continue;
            }
            $min = (int) ($row['min_amount_cents'] ?? 0);
            $max = $row['max_amount_cents'] ?? null;
            $out[] = [
                'min_mxn' => round($min / 100, 2),
                'max_mxn' => $max === null || $max === '' ? null : round((int) $max / 100, 2),
                'required_approvals' => (int) ($row['required_approvals'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $rules
     * @return list<array{min: int, max: ?int, required_approvals: int}>
     */
    private function mapBeneficiaryApprovalRulesPreviewForAuthorizer(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }
        $out = [];
        foreach ($rules as $row) {
            if (! is_array($row)) {
                continue;
            }
            $max = $row['max_beneficiaries'] ?? null;
            $out[] = [
                'min' => (int) ($row['min_beneficiaries'] ?? 0),
                'max' => $max === null || $max === '' ? null : (int) $max,
                'required_approvals' => (int) ($row['required_approvals'] ?? 0),
            ];
        }

        return $out;
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
        $amountRules = CouponAmountApprovalRule::query()
            ->orderBy('min_amount_cents')
            ->get()
            ->map(fn (CouponAmountApprovalRule $r) => [
                'min_amount_cents' => (int) $r->min_amount_cents,
                'max_amount_cents' => $r->max_amount_cents !== null ? (int) $r->max_amount_cents : null,
                'required_approvals' => (int) $r->required_approvals,
            ])
            ->values()
            ->all();

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
            'amount_rules' => $amountRules,
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

        $conceptPayload = $this->couponConceptService->resolveConceptPayload($data);
        $eligibilityAttributes = $this->couponEligibilityFormService->resolveAttributes($data);

        $coupon = Coupon::create(array_merge([
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'coupon_concept_id' => $conceptPayload['coupon_concept_id'],
            'concept_other' => $conceptPayload['concept_other'],
            'amount_cents' => (int) $data['amount_cents'],
            'remaining_cents' => $pending ? 0 : (int) $data['amount_cents'],
            'max_beneficiaries' => $data['max_beneficiaries'] ?? null,
            'type' => 'balance',
            'is_active' => $pending ? false : ($data['is_active'] ?? true),
            'approval_status' => $pending ? CouponApprovalStatus::PendingAuthorization : CouponApprovalStatus::Active,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ], $eligibilityAttributes));

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

    /**
     * Activa un cupón maestro pendiente (p. ej. autorización por correo) sin código,
     * cuando las reglas delegan la puesta en marcha al último autorizador multi-firma.
     */
    private function activateMasterCouponAsApprover(Coupon $coupon, int $actorUserId): void
    {
        if ($coupon->parent_coupon_id !== null) {
            return;
        }

        $coupon->approval_status = CouponApprovalStatus::Active;
        $coupon->is_active = true;
        $coupon->authorization_code_hash = null;
        $coupon->authorization_code_expires_at = null;
        $coupon->authorized_at = now();
        $coupon->authorized_by_user_id = $actorUserId;
        if ((int) $coupon->remaining_cents === 0 && (int) $coupon->amount_cents > 0) {
            $coupon->remaining_cents = (int) $coupon->amount_cents;
        }
        $coupon->updated_by_user_id = $actorUserId;
        $coupon->save();
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

        $rulesApprovals = $this->couponService->resolveRequiredApprovals((int) $parent->amount_cents, count($emails));
        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        $settings = CouponAdminSettings::singleton();

        $needsDeferredParentActivation = $parent->approval_status !== CouponApprovalStatus::Active
            || ! $parent->is_active;

        $requiredForRequest = $needsDeferredParentActivation
            ? max($rulesApprovals, 1)
            : $rulesApprovals;

        if (! $bypassAssignmentApprovals
            && $requiredForRequest > 0
            && ! ($isSuperadmin && $settings->superadmin_bypass_approvals)) {
            $authorizerIds = collect($request->input('authorizer_ids', []))->map(fn ($v) => (int) $v)->filter()->values()->all();
            if (count($authorizerIds) === 0) {
                $authorizerIds = $this->defaultAuthorizerAdministratorIds();
            }
            if (count($authorizerIds) === 0) {
                return redirect()->back()->flashMessage(
                    'Esta operación requiere aprobación según las reglas, pero no hay autorizadores configurados.',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'assignment',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: min($requiredForRequest, count($authorizerIds)),
                authorizerAdministratorIds: $authorizerIds,
                payload: [
                    'emails' => $emails,
                    'coupon_id' => $parent->id,
                    'send_notification' => $sendNotifications,
                    'activate_parent_on_execute' => $needsDeferredParentActivation,
                ],
                couponId: (int) $parent->id
            );

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.assign')->flashMessage(
                "Se creó la solicitud #{$approvalRequest->id} (".count($emails).' correo(s)). Al completarse las aprobaciones, el último autorizador activará el cupón y las asignaciones.'
            );
        }

        if ($needsDeferredParentActivation
            && $isSuperadmin
            && $settings->superadmin_bypass_approvals) {
            $this->activateMasterCouponAsApprover($parent, (int) $request->user()->id);
            $parent = $parent->fresh();
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
                'concept:id,title',
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
        $approvalUrl = $approvalRequest->type === 'settings'
            ? route('admin.coupons.settings', ['tab' => 'history'], absolute: true)
            : ($approvalRequest->coupon_id
                ? route('admin.coupons.show', ['coupon' => $approvalRequest->coupon_id], absolute: true)
                : route('admin.coupons.logs', absolute: true));

        Log::info('Preparando envío de correos para solicitud de aprobación de cupones', [
            'approval_request_id' => $approvalRequest->id,
            'type' => $approvalRequest->type,
            'status' => $approvalRequest->status,
            'approval_url' => $approvalUrl,
            'mail_mailer' => config('mail.default'),
            'authorizers_count' => $approvalRequest->authorizers->count(),
        ]);

        foreach ($approvalRequest->authorizers as $authorizer) {
            $email = $authorizer->administrator?->user?->email;
            if (! $email) {
                Log::warning('Autorizador sin correo para solicitud de aprobación de cupones', [
                    'approval_request_id' => $approvalRequest->id,
                    'approval_authorizer_id' => $authorizer->id,
                    'administrator_id' => $authorizer->administrator_id,
                ]);

                continue;
            }

            try {
                Log::info('Enviando correo de solicitud de aprobación de cupones', [
                    'approval_request_id' => $approvalRequest->id,
                    'approval_authorizer_id' => $authorizer->id,
                    'administrator_id' => $authorizer->administrator_id,
                    'to' => $email,
                    'approval_url' => $approvalUrl,
                    'mail_mailer' => config('mail.default'),
                ]);

                Mail::to($email)->send(new CouponApprovalRequestMail(
                    $approvalRequest,
                    $approvalUrl
                ));

                Log::info('Correo de solicitud de aprobación de cupones enviado', [
                    'approval_request_id' => $approvalRequest->id,
                    'approval_authorizer_id' => $authorizer->id,
                    'administrator_id' => $authorizer->administrator_id,
                    'to' => $email,
                    'approval_url' => $approvalUrl,
                    'mail_mailer' => config('mail.default'),
                ]);
            } catch (\Throwable $e) {
                Log::error('Error al enviar correo de solicitud de aprobación de cupones', [
                    'approval_request_id' => $approvalRequest->id,
                    'approval_authorizer_id' => $authorizer->id,
                    'administrator_id' => $authorizer->administrator_id,
                    'to' => $email,
                    'approval_url' => $approvalUrl,
                    'mail_mailer' => config('mail.default'),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function assertAssignableMasterCoupon(Coupon $coupon): void
    {
        if ($coupon->parent_coupon_id !== null) {
            abort(404);
        }

        if ($coupon->approval_status !== CouponApprovalStatus::Active || ! $coupon->is_active) {
            abort(422, 'El cupón no está disponible para asignaciones.');
        }
    }

    /**
     * @param  list<array{email: string, first_name: ?string, paternal_lastname: ?string, maternal_lastname: ?string}>  $rows
     */
    private function finishBeneficiaryAssignment(
        Request $request,
        Coupon $parent,
        array $rows,
        CouponBeneficiarySource $source,
        bool $sendNotifications,
    ): RedirectResponse {
        $preview = $this->couponBeneficiaryService->previewRows($parent, $rows);
        $confirmableCount = (int) ($preview['summary']['confirmable'] ?? 0);

        if ($confirmableCount === 0) {
            return redirect()->back()->flashMessage(
                'No hay beneficiarios válidos para confirmar.',
                'error'
            );
        }

        $bypassAssignmentApprovals = $this->couponIsLockedAfterApproval($parent);
        $rulesApprovals = $this->couponService->resolveRequiredApprovals((int) $parent->amount_cents, $confirmableCount);
        $isSuperadmin = (bool) $request->user()->administrator?->hasRole('superadmin');
        $settings = CouponAdminSettings::singleton();

        if (! $bypassAssignmentApprovals
            && $rulesApprovals > 0
            && ! ($isSuperadmin && $settings->superadmin_bypass_approvals)) {
            $authorizerIds = collect($request->input('authorizer_ids', []))->map(fn ($v) => (int) $v)->filter()->values()->all();
            if ($authorizerIds === []) {
                $authorizerIds = $this->defaultAuthorizerAdministratorIds();
            }
            if ($authorizerIds === []) {
                return redirect()->back()->flashMessage(
                    'Esta operación requiere aprobación según las reglas, pero no hay autorizadores configurados.',
                    'error'
                );
            }

            $approvalRequest = $this->couponService->createApprovalRequest(
                type: 'assignment',
                requestedByUserId: (int) $request->user()->id,
                requiredApprovals: min($rulesApprovals, count($authorizerIds)),
                authorizerAdministratorIds: $authorizerIds,
                payload: [
                    'beneficiary_rows' => $rows,
                    'coupon_id' => $parent->id,
                    'send_notification' => $sendNotifications,
                    'source' => $source->value,
                ],
                couponId: (int) $parent->id
            );

            $this->sendApprovalRequestMails($approvalRequest);

            return redirect()->route('admin.coupons.show', $parent)->flashMessage(
                "Se creó la solicitud #{$approvalRequest->id} ({$confirmableCount} beneficiario(s)). Al completarse las aprobaciones se procesarán asignaciones y pendientes."
            );
        }

        try {
            $result = $this->couponBeneficiaryService->confirmRows(
                $parent,
                $rows,
                $request->user(),
                $source,
                $sendNotifications,
                enforceMaxAssignmentAmount: ! $bypassAssignmentApprovals
            );
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        $msg = "Asignados: {$result['assigned_count']}. Pendientes de registro: {$result['pending_count']}.";
        if (($result['invitations_sent_count'] ?? 0) > 0) {
            $msg .= " Invitaciones enviadas: {$result['invitations_sent_count']}.";
        }
        if ($result['skipped_count'] > 0) {
            $msg .= " Omitidos: {$result['skipped_count']}.";
        }
        if (($result['invitation_warnings'] ?? []) !== []) {
            $msg .= ' Avisos de invitación: '.implode('; ', array_slice($result['invitation_warnings'], 0, 3));
            if (count($result['invitation_warnings']) > 3) {
                $msg .= '…';
            }
        }
        if ($result['errors'] !== []) {
            $msg .= ' Detalle: '.implode('; ', array_slice($result['errors'], 0, 3));
            if (count($result['errors']) > 3) {
                $msg .= '…';
            }
        }

        return redirect()->route('admin.coupons.show', $parent)->flashMessage($msg);
    }
}
