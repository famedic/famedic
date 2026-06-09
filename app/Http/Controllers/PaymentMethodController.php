<?php

namespace App\Http\Controllers;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\EfevooToken;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentMethod as StoredPaymentMethod;
use App\Services\CouponService;
use App\Contracts\EfevooPayGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\AppEnvironmentLabel;
use App\Support\MockEfevooPaymentSupport;
use App\Support\PaymentMethodIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    protected EfevooPayGateway $service;
    protected PaymentGatewayManager $gatewayManager;

    public function __construct(EfevooPayGateway $service, PaymentGatewayManager $gatewayManager)
    {
        $this->service = $service;
        $this->gatewayManager = $gatewayManager;
    }

    /* ==========================================================
     * INDEX
     * ========================================================== */

    public function index(Request $request, CouponService $couponService)
    {
        $customer = $request->user()->customer;

        $balanceCents = $couponService->getUserBalance($request->user()->id);

        $paymentMethods = [];

        if (config('payments.efevoopay_enabled', true)) {
            $tokens = EfevooToken::where('customer_id', $customer->id)
                ->active()
                ->excludeMockInProduction()
                ->orderByDesc('created_at')
                ->get();

            $paymentMethods = $tokens->unique(function (EfevooToken $t) {
                return $t->card_last_four . '-' . ($t->card_expiration ?? '');
            })->values()->all();
        }

        if (config('heybanco.enabled')) {
            $heyBancoMethods = StoredPaymentMethod::query()
                ->active()
                ->forProvider(config('heybanco.provider_key'))
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (StoredPaymentMethod $method) => [
                    'id' => $method->publicId(),
                    'provider' => $method->provider,
                    'alias' => $method->alias,
                    'card_brand' => $method->brand,
                    'card_last_four' => $method->last4,
                    'card_expiration' => ($method->exp_month ?? '') . substr((string) $method->exp_year, -2),
                    'card_holder' => $method->card_holder,
                    'environment' => config('heybanco.env'),
                ]);

            $paymentMethods = collect($paymentMethods)
                ->concat($heyBancoMethods)
                ->values()
                ->all();
        }

        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $paymentMethods,
            'heyBancoEnabled' => (bool) config('heybanco.enabled'),
            'efevoopayEnabled' => (bool) config('payments.efevoopay_enabled', true),
            'defaultPaymentProvider' => $this->gatewayManager->defaultProvider(),
            'environment' => config('efevoopay.environment'),
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'balanceCouponsCents' => $balanceCents,
            'formattedBalanceCoupons' => $balanceCents > 0 ? formattedCentsPrice($balanceCents) : null,
        ]);
    }

    /* ==========================================================
     * CREATE
     * ========================================================== */

    public function create(Request $request)
    {
        return Inertia::render('PaymentMethods/Create', [
            'returnUrl' => $request->query('return_url'),
            'heyBancoEnabled' => (bool) config('heybanco.enabled'),
            'efevoopayEnabled' => (bool) config('payments.efevoopay_enabled', true),
            'defaultPaymentProvider' => $this->gatewayManager->defaultProvider(),
            'heyBancoTestCards' => config('heybanco.enabled') ? config('heybanco.test_cards') : [],
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'requires_3ds' => ! MockEfevooPaymentSupport::isMockMode(),
            ],
            'hasPending3ds' => false,
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'mockTestCards' => MockEfevooPaymentSupport::isMockMode()
                ? $this->service->getTestCards()
                : [],
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
        ]);
    }

    /* ==========================================================
     * STORE (INICIAR 3DS)
     * ========================================================== */

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_number' => 'required|string',
            'exp_month' => 'required|string',
            'exp_year' => 'required|string',
            'cvv' => 'required|string',
            'card_holder' => 'required|string',
            'alias' => 'required|string',
            'terms_accepted' => 'accepted',
            'payment_provider' => 'nullable|string|in:efevoopay,hey_banco',
        ]);

        $customer = $request->user()->customer;
        $paymentProvider = $validated['payment_provider'] ?? $this->gatewayManager->defaultProvider();

        if ($paymentProvider === 'hey_banco' && config('heybanco.enabled')) {
            return $this->storeHeyBancoCard($request, $customer, $validated);
        }

        if (! config('payments.efevoopay_enabled', true)) {
            return back()->withErrors([
                'payment_provider' => (string) config('payments.legacy_efevoo_rejection_message'),
            ]);
        }

        $month = str_pad($validated['exp_month'], 2, '0', STR_PAD_LEFT);
        $year = substr($validated['exp_year'], -2);

        if ((int) $month < 1 || (int) $month > 12) {
            return back()->withErrors([
                'exp_month' => 'Mes inválido'
            ]);
        }

        $currentYear = date('y');
        $currentMonth = date('m');

        if (
            (int) $year < (int) $currentYear ||
            ((int) $year === (int) $currentYear && (int) $month < (int) $currentMonth)
        ) {

            return back()->withErrors([
                'exp_year' => 'La tarjeta está vencida'
            ]);
        }

        $cardData = [
            'card_number' => $validated['card_number'],
            'expiration' => $month . $year,
            'cvv' => $validated['cvv'],
            'card_holder' => $validated['card_holder'],
            'alias' => $validated['alias'],
            'amount' => config('efevoopay.test_amounts.default') / 100,
        ];

        if (MockEfevooPaymentSupport::isMockMode()) {
            $result = $this->service->tokenizeCard($cardData, $customer->id);

            if (! $result['success']) {
                return back()->withErrors([
                    'error' => $result['message'] ?? 'No se pudo registrar la tarjeta (simulación)',
                ]);
            }

            $returnUrl = $request->input('return_url') ?? $request->query('return_url');
            $redirect = $returnUrl
                ? redirect($returnUrl)
                : redirect()->route('payment-methods.index');

            return $redirect->with('success', 'Tarjeta de prueba registrada correctamente (sin cargo real).');
        }

        $returnUrl = $request->input('return_url') ?? $request->query('return_url');

        Log::info('[3DS] Iniciando proceso');

        $result = $this->service->initiate3DS($cardData, $customer->id);

        if (!$result['success']) {
            return back()->withErrors([
                'error' => $result['message'] ?? 'Error iniciando verificación'
            ]);
        }

        Session::put('3ds_card_data_' . $result['session_id'], $cardData);

        if ($returnUrl) {
            Session::put('3ds_return_url_' . $result['session_id'], $returnUrl);
        }

        return redirect()->route('payment-methods.3ds-redirect', [
            'sessionId' => $result['session_id']
        ]);
    }

    public function showMock3ds(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        return Inertia::render('PaymentMethods/MockThreeDS', [
            'sessionId' => $session->id,
            'cardLastFour' => $session->card_last_four,
            'amount' => $session->amount,
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
        ]);
    }

    /* ==========================================================
     * VIEW IFRAME 3DS
     * ========================================================== */

    public function show3dsRedirect($sessionId)
    {
        $session = Efevoo3dsSession::findOrFail($sessionId);

        return Inertia::render('PaymentMethods/ThreeDSRedirect', [
            'sessionId' => $session->id,
            'url3ds' => $session->url_3dsecure,
            'token3ds' => $session->token_3dsecure,
        ]);
    }

    /* ==========================================================
     * RESULT VIEW
     * ========================================================== */

    public function show3dsResult(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $success = $session->status === 'completed';
        $displayMessage = $success
            ? 'Tarjeta verificada correctamente'
            : ($session->error_message ?: $this->resolveStatusMessage($session->status));

        $returnUrl = Session::pull('3ds_return_url_' . $session->id);

        return Inertia::render('PaymentMethods/ThreeDSResult', [
            'sessionId' => $session->id,
            'success' => $success,
            'message' => $displayMessage,
            'errorDetail' => $session->error_message,
            'status' => $session->status,
            'cardLastFour' => $session->card_last_four,
            'amount' => $session->amount,
            'createdAt' => $session->created_at,
            'returnUrl' => $returnUrl,
        ]);
    }

    /* ==========================================================
     * DELETE TOKEN
     * ========================================================== */

    public function destroy(Request $request, $tokenId)
    {
        $customer = $request->user()->customer;
        $tokenKey = urldecode((string) $tokenId);

        if (PaymentMethodIdentifier::isHeyBanco($tokenKey)) {
            $method = StoredPaymentMethod::query()
                ->where('user_id', $request->user()->id)
                ->where('id', PaymentMethodIdentifier::heyBancoId($tokenKey))
                ->firstOrFail();

            $method->update(['status' => 'inactive']);

            return back()->with('success', 'Tarjeta eliminada correctamente');
        }

        $token = EfevooToken::where('id', $tokenKey)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $token->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);

        return back()->with('success', 'Tarjeta eliminada correctamente');
    }

    private function storeHeyBancoCard(Request $request, $customer, array $validated)
    {
        $returnUrl = $request->input('return_url') ?? $request->query('return_url');

        try {
            $gateway = $this->gatewayManager->forProvider('hey_banco');
            $gateway->tokenize($customer, $validated);
        } catch (HeyBancoPaymentException $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }

        $redirect = $returnUrl
            ? redirect($returnUrl)
            : redirect()->route('payment-methods.index');

        return $redirect->with('success', 'Tarjeta registrada correctamente con Hey Banco.');
    }

    /* ==========================================================
     * HEALTH CHECK
     * ========================================================== */

    public function health()
    {
        return response()->json(
            $this->service->healthCheck()
        );
    }

    /* ==========================================================
     * POLLING STATUS 3DS
     * ========================================================== */
    public function check3dsStatus(Request $request, $sessionId)
    {
        Log::info('[3DS] check3dsStatus llamado', [
            'session_id' => $sessionId
        ]);

        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        // Si ya es estado final, no reprocesar
        if ($session->status === 'mock_pending') {
            $cardData = Session::get('3ds_card_data_' . $sessionId);

            if ($cardData) {
                $this->service->complete3DS($session, $cardData);
                $session->refresh();
            }
        }

        if (
            in_array($session->status, [
                'completed',
                'declined',
                'tokenization_failed'
            ])
        ) {
            $message = $session->error_message ?: $this->resolveStatusMessage($session->status);
            return response()->json([
                'final' => true,
                'status' => $session->status,
                'message' => $message,
                'error_detail' => $session->error_message,
            ]);
        }

        // Recuperar cardData correcto
        $cardData = Session::get('3ds_card_data_' . $sessionId);

        if (!$cardData) {
            return response()->json([
                'final' => true,
                'status' => 'error',
                'message' => 'Sesión 3DS inválida'
            ]);
        }

        $result = $this->service->complete3DS($session, $cardData);

        $session->refresh();

        $message = $session->error_message
            ?? $result['message']
            ?? $this->resolveStatusMessage($session->status);

        return response()->json([
            'final' => in_array($session->status, [
                'completed',
                'declined',
                'tokenization_failed'
            ]),
            'status' => $session->status,
            'message' => $message,
            'error_detail' => $session->error_message,
            'error_type' => $result['error_type'] ?? null,
        ]);
    }

    private function resolveStatusMessage(string $status): string
    {
        return match ($status) {
            'completed' => 'Tarjeta verificada y guardada correctamente.',
            'declined' => 'La verificación fue rechazada por tu banco. Puede deberse a que cancelaste el proceso o el banco no autorizó la operación.',
            'tokenization_failed' => 'La tarjeta fue autenticada, pero no pudo guardarse. Revisa el motivo más abajo o contacta a soporte.',
            'authenticated' => 'Verificación exitosa. Guardando tarjeta...',
            'pending' => 'Esperando que completes la verificación en la ventana de tu banco.',
            default => 'Procesando verificación...'
        };
    }
}