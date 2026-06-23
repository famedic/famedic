<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PromoType;
use App\Exceptions\PromoCodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSharedPromoCodeRequest;
use App\Models\CouponAdminSettings;
use App\Models\PromoCode;
use App\Services\CouponAssignOtpService;
use App\Services\CouponCreatedAuthorizerNotifier;
use App\Services\PromoCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PromoCodeController extends Controller
{
    public function __construct(
        private PromoCodeService $promoCodeService,
        private CouponAssignOtpService $couponAssignOtpService,
        private CouponCreatedAuthorizerNotifier $couponCreatedAuthorizerNotifier,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', PromoCode::class);

        $search = trim((string) $request->input('search', ''));

        $promoCodes = PromoCode::query()
            ->with('coupon')
            ->where('promo_type', PromoType::Shared)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', '%'.PromoCode::normalizeCode($search).'%')
                        ->orWhereHas('coupon', fn ($q) => $q->where('description', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $promoCodes->getCollection()->transform(
            fn (PromoCode $promoCode) => $this->promoCodeService->presentForAdminIndex($promoCode)
        );

        return Inertia::render('Admin/Coupons/PromoCodes/Index', [
            'promoCodes' => $promoCodes,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        $this->authorize('create', PromoCode::class);

        $settings = CouponAdminSettings::singleton();

        return Inertia::render('Admin/Coupons/PromoCodes/Create', [
            'settings' => $settings,
            'creationOtpRequired' => $this->couponAssignOtpService->isRequired(),
            'rulesForUi' => [
                'max_assignment_amount_cents' => $settings->max_assignment_amount_cents,
                'max_assignment_amount_mxn' => $settings->max_assignment_amount_cents !== null
                    ? $settings->max_assignment_amount_cents / 100
                    : null,
            ],
        ]);
    }

    public function store(StoreSharedPromoCodeRequest $request): RedirectResponse
    {
        $this->authorize('create', PromoCode::class);

        if ($this->couponAssignOtpService->isRequired() && ! $request->filled('otp_verification_token')) {
            throw ValidationException::withMessages([
                'otp_verification_token' => 'Debes completar la verificación OTP antes de crear el código promocional.',
            ]);
        }

        $data = $request->validatedPayload();

        if ($this->couponAssignOtpService->isRequired()) {
            try {
                $this->couponAssignOtpService->assertVerified(
                    $request->user(),
                    (string) $request->input('otp_verification_token'),
                    $request->otpPayload(),
                );
            } catch (\DomainException $e) {
                return redirect()->back()->withInput()->flashMessage($e->getMessage(), 'error');
            }
        }

        try {
            $promoCode = $this->promoCodeService->createSharedPromoFromAdmin($data, $request->user());
        } catch (PromoCodeException $e) {
            return redirect()->back()->withInput()->flashMessage($e->getMessage(), 'error');
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($this->couponAssignOtpService->isRequired()) {
            try {
                $this->couponAssignOtpService->consumeVerificationToken(
                    $request->user(),
                    (string) $request->input('otp_verification_token'),
                );
            } catch (\DomainException $e) {
                Log::warning('promo_creation_otp_consume_failed_after_persist', [
                    'user_id' => $request->user()->id,
                    'promo_code_id' => $promoCode->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->couponCreatedAuthorizerNotifier->notify($promoCode->coupon, $request->user());
        }

        return redirect()
            ->route('admin.coupons.promo-codes.show', $promoCode)
            ->flashMessage('Código promocional creado correctamente.');
    }

    public function show(PromoCode $promoCode): InertiaResponse
    {
        $this->authorize('view', $promoCode);

        abort_unless($promoCode->promo_type === PromoType::Shared, 404);

        return Inertia::render('Admin/Coupons/PromoCodes/Show', [
            'promoCode' => $this->promoCodeService->presentForAdminShow($promoCode),
        ]);
    }

    public function deactivate(Request $request, PromoCode $promoCode): RedirectResponse
    {
        $this->authorize('update', $promoCode);

        abort_unless($promoCode->promo_type === PromoType::Shared, 404);

        if (! $promoCode->is_active) {
            return redirect()
                ->route('admin.coupons.promo-codes.show', $promoCode)
                ->flashMessage('El código promocional ya estaba desactivado.');
        }

        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirma la desactivación del código promocional.',
        ]);

        $this->promoCodeService->deactivate($promoCode);

        return redirect()
            ->route('admin.coupons.promo-codes.show', $promoCode)
            ->flashMessage('Código promocional desactivado.');
    }

    public function checkCode(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', PromoCode::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $available = $this->promoCodeService->isCodeAvailable((string) $validated['code']);

        return response()->json([
            'available' => $available,
            'normalized' => PromoCode::normalizeCode((string) $validated['code']),
        ]);
    }
}
