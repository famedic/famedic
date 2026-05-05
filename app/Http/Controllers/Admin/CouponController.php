<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CouponApprovalStatus;
use App\Http\Controllers\Controller;
use App\Imports\CouponsExcelImport;
use App\Mail\CouponAuthorizationRequestedMail;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        return Inertia::render('Admin/Coupons/Index', [
            'coupons' => $coupons,
            'filters' => [
                'search' => $request->input('search', ''),
                'usage' => $request->input('usage', 'all'),
                'user_email' => $request->input('user_email', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
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

    public function settings(): InertiaResponse
    {
        $this->authorize('viewAny', Coupon::class);

        return Inertia::render('Admin/Coupons/Settings', [
            'settings' => CouponAdminSettings::singleton(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $data = $request->validate([
            'max_assignment_amount_mxn' => ['nullable', 'numeric', 'min:0'],
            'max_assignments_per_day' => ['nullable', 'integer', 'min:1'],
            'authorization_email' => ['nullable', 'email'],
            'require_authorization' => ['boolean'],
        ]);

        $settings = CouponAdminSettings::singleton();
        $settings->max_assignment_amount_cents = isset($data['max_assignment_amount_mxn']) && $data['max_assignment_amount_mxn'] !== null && $data['max_assignment_amount_mxn'] !== ''
            ? (int) round((float) $data['max_assignment_amount_mxn'] * 100)
            : null;
        $settings->max_assignments_per_day = $data['max_assignments_per_day'] ?? null;
        $settings->authorization_email = isset($data['authorization_email'])
            ? trim((string) $data['authorization_email']) : null;
        if ($settings->authorization_email === '') {
            $settings->authorization_email = null;
        }
        $settings->require_authorization = $data['require_authorization'] ?? false;
        $settings->save();

        return redirect()->route('admin.coupons.settings')->flashMessage('Reglas de cupones actualizadas.');
    }

    public function create(): InertiaResponse
    {
        $this->authorize('create', Coupon::class);

        return Inertia::render('Admin/Coupons/Create', [
            'settings' => CouponAdminSettings::singleton(),
        ]);
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

    public function show(Coupon $coupon): InertiaResponse|RedirectResponse
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

        return Inertia::render('Admin/Coupons/Show', [
            'coupon' => $coupon,
            'beneficiaryRows' => $beneficiaryRows,
            'authorizationRecipientEmail' => CouponAdminSettings::singleton()->authorization_email,
            'mailSetupHint' => $pendingAuth ? $this->mailSetupHintForCouponShow() : null,
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

        return Inertia::render('Admin/Coupons/Edit', [
            'coupon' => $coupon,
        ]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);

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

    public function assignForm(): InertiaResponse
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

        return Inertia::render('Admin/Coupons/Assign', [
            'assignableCoupons' => $assignable,
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'coupon_id' => ['required', 'integer', 'exists:coupons,id'],
            'send_notification' => ['boolean'],
        ]);

        $parent = Coupon::query()
            ->rootCoupons()
            ->whereKey($data['coupon_id'])
            ->firstOrFail();

        $user = User::where('email', $data['email'])->firstOrFail();

        try {
            $this->couponService->assignUserToCampaignCoupon(
                $user,
                $parent,
                $data['send_notification'] ?? true,
                (int) $request->user()->id
            );
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()->route('admin.coupons.show', $parent)->flashMessage('Beneficiario asignado al cupón.');
    }

    public function importForm(): InertiaResponse
    {
        $this->authorize('create', Coupon::class);

        return Inertia::render('Admin/Coupons/Import');
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
}
