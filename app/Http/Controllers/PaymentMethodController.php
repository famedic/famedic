<?php

namespace App\Http\Controllers;

use App\Models\EfevooToken;
use App\Models\Efevoo3dsSession;
use App\Services\EfevooPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    protected EfevooPayService $service;

    public function __construct(EfevooPayService $service)
    {
        $this->service = $service;
    }

    /* ==========================================================
     * INDEX
     * ========================================================== */

    public function index(Request $request)
    {
        $customer = $request->user()->customer;

        $tokens = EfevooToken::where('customer_id', $customer->id)
            ->where('is_active', true)
            ->latest()
            ->get();

        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $tokens,
            'environment' => config('efevoopay.environment'),
        ]);
    }

    /* ==========================================================
     * CREATE
     * ========================================================== */

    public function create()
    {
        return Inertia::render('PaymentMethods/Create', [
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'requires_3ds' => true,
            ],
            'hasPending3ds' => false,
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
        ]);

        $customer = $request->user()->customer;

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

        Log::info('[3DS] Iniciando proceso');

        $result = $this->service->initiate3DS($cardData, $customer->id);

        if (!$result['success']) {
            return back()->withErrors([
                'error' => $result['message'] ?? 'Error iniciando verificación'
            ]);
        }

        // Guardar datos de tarjeta por sessionId
        Session::put('3ds_card_data_' . $result['session_id'], $cardData);

        return redirect()->route('payment-methods.3ds-redirect', [
            'sessionId' => $result['session_id']
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

        return Inertia::render('PaymentMethods/ThreeDSResult', [
            'sessionId' => $session->id,
            'success' => $success,
            'message' => $success
                ? 'Tarjeta verificada correctamente'
                : 'La verificación no fue completada',
            'status' => $session->status,
            'cardLastFour' => $session->card_last_four,
            'amount' => $session->amount,
            'createdAt' => $session->created_at,
        ]);
    }

    /* ==========================================================
     * DELETE TOKEN
     * ========================================================== */

    public function destroy(Request $request, $tokenId)
    {
        $customer = $request->user()->customer;

        $token = EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $token->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);

        return back()->with('success', 'Tarjeta eliminada correctamente');
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

        if ($session->status === 'completed') {
            return response()->json([
                'success' => true
            ]);
        }

        // Aquí llamamos a complete3DS
        $cardData = Session::get('3ds_card_data_' . $sessionId);

        if (!$cardData) {
            return response()->json([
                'success' => false
            ]);
        }

        $this->service->complete3DS($session, $cardData);
        if ($session->status === 'completed') {
            Session::forget('3ds_card_data_' . $sessionId);
        }

        $session->refresh();

        return response()->json([
            'success' => $session->status === 'completed',
            'status' => $session->status
        ]);
    }
}