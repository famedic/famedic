<?php

namespace App\Http\Controllers;

//use App\Services\EfevooPayFactoryService;
use App\Services\EfevooPayService;
use App\Http\Requests\PaymentMethods\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethods\DestroyPaymentMethodRequest;
use App\Models\EfevooToken;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class PaymentMethodController extends Controller
{
    protected $efevooPayService;

    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function index(Request $request)
    {
        $customer = $this->getCustomer($request->user());

        if (!$customer) {
            return redirect()->route('dashboard')
                ->with('error', 'No tienes un perfil de cliente configurado.');
        }

        $tokens = EfevooToken::where('customer_id', $customer->id)
            ->active()
            ->latest()
            ->get()
            ->map(function ($token) {
                return $this->formatTokenForFrontend($token);
            });

        // Obtener tarjetas de prueba del simulador si está disponible
        $testCards = $this->efevooPayService->getTestCards();

        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $tokens,
            'hasOdessaPay' => $customer->has_odessa_afiliate_account ?? false,
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'force_simulation' => config('efevoopay.force_simulation', false),
            ],
            'testCards' => $testCards, // Enviar tarjetas de prueba al frontend
        ]);
    }

    public function create(Request $request)
    {
        $customer = $this->getCustomer($request->user());

        if (!$customer) {
            return redirect()->route('dashboard')
                ->with('error', 'No tienes un perfil de cliente configurado.');
        }

        // Obtener tarjetas de prueba
        $testCards = $this->efevooPayService->getTestCards();

        return Inertia::render('PaymentMethods/Create', [
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'force_simulation' => config('efevoopay.force_simulation', false),
            ],
            'testCards' => $testCards,
        ]);
    }

    public function store(StorePaymentMethodRequest $request)
    {
        try {
            $user = $request->user();
            $customer = $this->getCustomer($user);

            if (!$customer) {
                return back()->withErrors([
                    'card' => 'No tienes un perfil de cliente configurado.',
                ]);
            }

            // DEBUG: Verificar datos del formulario
            Log::info('=== DATOS DEL FORMULARIO ===', [
                'card_number_preview' => $request->card_number ? substr(str_replace(' ', '', $request->card_number), 0, 6) . '...' : 'empty',
                'exp_month' => $request->exp_month,
                'exp_year_short' => $request->exp_year_short,
                'expiration_combined' => $request->exp_month . $request->exp_year_short,
                'card_holder' => $request->card_holder ? 'set' : 'empty',
                'alias' => $request->alias,
            ]);

            // Validar que exp_month y exp_year_short tengan 2 dígitos
            if (strlen($request->exp_month) !== 2 || strlen($request->exp_year_short) !== 2) {
                Log::error('Formato de fecha inválido', [
                    'exp_month' => $request->exp_month,
                    'exp_month_length' => strlen($request->exp_month),
                    'exp_year_short' => $request->exp_year_short,
                    'exp_year_short_length' => strlen($request->exp_year_short),
                ]);

                return back()->withErrors([
                    'expiration' => 'El formato de fecha debe ser MM/YY con 2 dígitos para mes y año',
                ]);
            }

            // Preparar datos para tokenización
            $cardData = [
                'card_number' => str_replace(' ', '', $request->card_number),
                'expiration' => $request->exp_month . $request->exp_year_short,
                'card_holder' => $request->card_holder,
                'amount' => config('efevoopay.test_amounts.default') / 100,
                'alias' => $request->alias,
            ];

            // Verificar formato de expiración
            if (!preg_match('/^\d{4}$/', $cardData['expiration'])) {
                Log::error('Expiración no tiene 4 dígitos', [
                    'expiration' => $cardData['expiration'],
                    'exp_month' => $request->exp_month,
                    'exp_year_short' => $request->exp_year_short,
                ]);

                return back()->withErrors([
                    'expiration' => 'La fecha de expiración debe tener 4 dígitos (MMYY)',
                ]);
            }

            // LOG DETALLADO ANTES DE TOKENIZAR
            Log::info('=== INICIANDO TOKENIZACIÓN ===', [
                'customer_id' => $customer->id,
                'expiration_for_api' => 'Convertirá: ' . $cardData['expiration'] . ' (MMYY) → ' .
                    substr($cardData['expiration'], 2, 2) . substr($cardData['expiration'], 0, 2) . ' (YYMM)',
                'amount' => $cardData['amount'],
                'last_four' => substr($cardData['card_number'], -4),
            ]);

            // CAMBIA ESTA LÍNEA: Usar fastTokenize en lugar de tokenizeCard
            $result = $this->efevooPayService->fastTokenize($cardData, $customer->id);

            // LOG DETALLADO DESPUÉS DE TOKENIZAR
            Log::info('=== RESULTADO DE TOKENIZACIÓN ===', [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Sin mensaje',
                'has_token_id' => isset($result['token_id']),
                'token_id' => $result['token_id'] ?? null,
                'has_errors' => isset($result['errors']),
                'errors' => $result['errors'] ?? null,
                'code' => $result['code'] ?? null,
                'has_data' => isset($result['data']),
            ]);

            if (isset($result['data'])) {
                Log::debug('Datos completos de respuesta', [
                    'data_keys' => array_keys($result['data']),
                    'has_token_usuario' => isset($result['data']['token_usuario']),
                    'has_token' => isset($result['data']['token']),
                    'descripcion' => $result['data']['descripcion'] ?? null,
                ]);
            }

            if (!$result['success']) {
                $errorMessage = $result['message'] ?? 'Error al tokenizar la tarjeta';

                // Si hay errores de validación, mostrarlos
                if (isset($result['errors'])) {
                    return back()->withErrors($result['errors']);
                }

                return back()->withErrors([
                    'card' => $errorMessage,
                ]);
            }

            Log::info('Tokenización exitosa', [
                'token_id' => $result['token_id'] ?? null,
                'customer_id' => $customer->id,
                'efevoo_token' => $result['efevoo_token'] ?? null,
                'card_token' => $result['card_token'] ?? null,
            ]);

            return redirect()->route('payment-methods.index')
                ->with('success', 'Tarjeta guardada exitosamente. Se realizó un cargo de verificación.');

        } catch (\Exception $e) {
            Log::error('Excepción en store payment method', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return back()->withErrors([
                'card' => 'Error al procesar la tarjeta: ' . $e->getMessage(),
            ]);
        }
    }

    public function destroy(DestroyPaymentMethodRequest $request, string $tokenId)
    {
        \Log::info('=== INICIANDO ELIMINACIÓN ===', [
            'user_id' => $request->user()->id,
            'token_id_recibido' => $tokenId,
            'input_data' => $request->all(),
        ]);

        $user = $request->user();
        $customer = $this->getCustomer($user);

        if (!$customer) {
            \Log::warning('Customer no encontrado', ['user_id' => $user->id]);
            return back()->withErrors([
                'card' => 'No tienes un perfil de cliente configurado.',
            ]);
        }

        \Log::info('Buscando token', [
            'token_id' => $tokenId,
            'customer_id' => $customer->id,
        ]);

        $token = EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$token) {
            \Log::warning('Token no encontrado', [
                'token_id' => $tokenId,
                'customer_id' => $customer->id,
            ]);
            return back()->withErrors([
                'card' => 'La tarjeta no fue encontrada.',
            ]);
        }

        \Log::info('Token encontrado', [
            'token_id' => $token->id,
            'alias' => $token->alias,
            'last4' => $token->card_last_four,
            'is_active_actual' => $token->is_active,
        ]);

        // Soft delete
        $token->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);

        // Verificar la actualización
        $token->refresh();
        \Log::info('Token actualizado', [
            'is_active_nuevo' => $token->is_active,
            'deleted_at' => $token->deleted_at,
        ]);

        \Log::info('Tarjeta eliminada', [
            'token_id' => $token->id,
            'customer_id' => $customer->id,
            'alias' => $token->alias,
        ]);

        return back()->with('success', 'Tarjeta eliminada exitosamente.');
    }

    public function health()
    {
        $health = $this->efevooPayService->healthCheck();

        return response()->json([
            'status' => $health['status'],
            'environment' => $health['environment'],
            'message' => $health['status'] === 'online' ? 'Servicio operativo' : 'Servicio inactivo',
            'timestamp' => $health['timestamp'],
        ]);
    }

    /**
     * Obtener el customer del usuario
     */
    private function getCustomer(User $user): ?Customer
    {
        // Primero intentar obtener el customer directamente
        if ($user->customer) {
            return $user->customer;
        }

        // Si no existe, buscar por user_id
        return Customer::where('user_id', $user->id)->first();
    }

    /**
     * Formatear token para frontend
     */
    private function formatTokenForFrontend(EfevooToken $token): array
    {
        return [
            'id' => $token->id,
            'object' => 'efevoo_token',
            'card' => [
                'brand' => strtolower($token->card_brand),
                'last4' => $token->card_last_four,
                'exp_month' => substr($token->card_expiration, 0, 2),
                'exp_year' => '20' . substr($token->card_expiration, 2, 2),
                'exp_year_short' => substr($token->card_expiration, 2, 2),
            ],
            'billing_details' => [
                'name' => $token->card_holder,
            ],
            'alias' => $token->alias ?? $token->generateAlias(),
            'created' => $token->created_at->timestamp,
            'metadata' => array_merge(
                $token->metadata ?? [],
                [
                    'alias' => $token->alias,
                    'environment' => $token->environment,
                    'expires_at' => $token->expires_at?->toISOString(),
                ]
            ),
        ];
    }

    /**
     * Verificar si excede límite de tarjetas
     */
    private function exceedsCardLimit(int $customerId): bool
    {
        return EfevooToken::where('customer_id', $customerId)
            ->active()
            ->count() >= 5;
    }

    /**
     * Verificar transacciones pendientes
     */
    private function hasPendingTransactions(EfevooToken $token): bool
    {
        return $token->transactions()
            ->whereIn('status', ['pending', 'approved'])
            ->where('transaction_type', 'payment')
            ->exists();
    }

    /**
     * Método para actualizar el alias de una tarjeta
     */
    public function updateAlias(Request $request, string $tokenId)
    {
        $user = $request->user();
        $customer = $this->getCustomer($user);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes un perfil de cliente configurado.'
            ], 403);
        }

        $request->validate([
            'alias' => 'required|string|max:50',
        ]);

        $token = EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $token->update([
            'alias' => $request->alias,
        ]);

        Log::info('Alias actualizado', [
            'token_id' => $token->id,
            'old_alias' => $token->getOriginal('alias'),
            'new_alias' => $request->alias,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alias actualizado correctamente',
            'alias' => $request->alias,
        ]);
    }

    // Añadir método para forzar simulación (útil para pruebas)
    public function forceSimulation(Request $request)
    {
        $request->validate([
            'force' => 'required|boolean',
        ]);

        $this->efevooPayService->forceSimulation($request->force);

        return response()->json([
            'success' => true,
            'message' => $request->force
                ? 'Simulación forzada activada'
                : 'Simulación forzada desactivada',
            'force_simulation' => $request->force,
        ]);
    }
}