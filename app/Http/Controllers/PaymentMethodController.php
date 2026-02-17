<?php

namespace App\Http\Controllers;

use App\Services\EfevooPayService;
use App\Models\EfevooToken;
use App\Models\Efevoo3dsSession;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    protected EfevooPayService $service;

    public function __construct(EfevooPayService $service)
    {
        $this->service = $service;
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;

        $tokens = EfevooToken::where('customer_id', $customer->id)
            ->active()
            ->latest()
            ->get();

        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $tokens,
            'environment' => config('efevoopay.environment'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */
    public function create(Request $request)
    {
        \Log::info('🔵 PaymentMethodController::create');

        $customer = $this->getCustomer($request->user());

        if (!$customer) {
            return redirect()->route('dashboard')
                ->with('error', 'No tienes un perfil de cliente configurado.');
        }

        /*$hasPending3ds = \App\Models\Efevoo3dsSession::where('customer_id', $customer->id)
            ->whereIn('status', ['pending', 'redirect_required'])
            ->exists();*/
        $hasPending3ds = false;

        return \Inertia\Inertia::render('PaymentMethods/Create', [
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'requires_3ds' => config('efevoopay.requires_3ds', true),
            ],
            'hasPending3ds' => $hasPending3ds,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE (INICIAR 3DS)
    |--------------------------------------------------------------------------
    */
    public function store(Request $request)
    {
        $customer = $request->user()->customer;

        $cardData = [
            'card_number' => str_replace(' ', '', $request->card_number),
            'expiration' => $request->exp_month . $request->exp_year_short,
            'cvv' => $request->cvv,
            'card_holder' => $request->card_holder,
            'alias' => $request->alias,
            'amount' => config('efevoopay.tokenization_amount', 1.00),
        ];

        Log::info('🔵 Iniciando 3DS', [
            'customer_id' => $customer->id,
            'last4' => substr($cardData['card_number'], -4),
        ]);

        $result = $this->service->initiate3DSProcess($cardData, $customer->id);

        if (!$result['success']) {
            return back()->withErrors([
                'card' => $result['message'] ?? 'Error iniciando verificación 3DS'
            ]);
        }

        if (!empty($result['requires_3ds']) && $result['requires_3ds'] === true) {

            session([
                '3ds_card_data_' . $result['session_id'] => $cardData
            ]);

            // 👇 ESTA ES LA FORMA CORRECTA CON INERTIA
            return Inertia::location(
                route('payment-methods.3ds-redirect', [
                    'sessionId' => $result['session_id']
                ])
            );
        }

        return redirect()->route('payment-methods.index')
            ->with('success', 'Tarjeta guardada correctamente');
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK STATUS (COMPLETAR 3DS)
    |--------------------------------------------------------------------------
    */
    public function check3dsStatus(Request $request, string $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $cardData = session('3ds_card_data_' . $sessionId);

        if (!$cardData) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de tarjeta no encontrados'
            ]);
        }

        Log::info('🔵 Verificando estado 3DS', [
            'session_id' => $sessionId
        ]);

        $result = $this->service->complete3DS(
            $session,
            $cardData
        );

        if ($result['success']) {
            session()->forget('3ds_card_data_' . $sessionId);
        }

        return response()->json($result);
    }

    /*
    |--------------------------------------------------------------------------
    | RESULT VIEW
    |--------------------------------------------------------------------------
    */
    public function show3dsResult(Request $request, string $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $success = $session->status === Efevoo3dsSession::STATUS_COMPLETED;

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


    /*
    |--------------------------------------------------------------------------
    | DELETE TOKEN
    |--------------------------------------------------------------------------
    */
    public function destroy(Request $request, string $tokenId)
    {
        $customer = $request->user()->customer;

        $token = EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $token->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);

        return back()->with('success', 'Tarjeta eliminada');
    }

    /*
    |--------------------------------------------------------------------------
    | HEALTH
    |--------------------------------------------------------------------------
    */
    public function health()
    {
        return response()->json(
            $this->service->healthCheck()
        );
    }

    /*
        |--------------------------------------------------------------------------
        | Obtener el customer del usuario autenticado
        |--------------------------------------------------------------------------
        */
    private function getCustomer(\App\Models\User $user): ?\App\Models\Customer
    {
        if (!$user) {
            return null;
        }

        // Si existe relación directa
        if ($user->customer) {
            return $user->customer;
        }

        // Fallback por user_id
        return \App\Models\Customer::where('user_id', $user->id)->first();
    }

    public function show3dsRedirect(Request $request, string $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        if ($session->status !== 'redirect_required') {
            return redirect()->route('payment-methods.index');
        }

        $iframeHtml = '
        <form id="threeDSForm" method="POST" action="' . $session->url_3dsecure . '">
            <input type="hidden" name="token_3dsecure" value="' . $session->token_3dsecure . '" />
        </form>
        <script>
            document.getElementById("threeDSForm").submit();
        </script>
    ';

        return Inertia::render('PaymentMethods/ThreeDSRedirect', [
            'sessionId' => $session->id,
            'orderId' => $session->order_id,
            'url3ds' => $session->url_3dsecure,
            'token3ds' => $session->token_3dsecure,
            'iframeHtml' => $iframeHtml,
        ]);
    }


}
