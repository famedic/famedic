<?php

namespace App\Http\Controllers\EfevooPay;

use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\CustomerPaymentMethod;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Http\Controllers\Controller;
class PaymentMethodCardController extends Controller
{
    private PaymentMethodService $paymentMethodService;
    
    public function __construct(PaymentMethodService $paymentMethodService)
    {
        $this->middleware('auth');
        $this->paymentMethodService = $paymentMethodService;
    }
    
    /**
     * Listar tarjetas del usuario
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;
        
        if (!$customer) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'No se encontró tu perfil de cliente.']);
        }
        
        $result = $this->paymentMethodService->listCustomerCards($customer);
        
        return Inertia::render('PaymentMethods/Index', [
            'paymentMethods' => $result['cards'] ?? [],
            'hasDefault' => $result['has_default'] ?? false,
            'canAddMore' => ($result['count'] ?? 0) < 10,
            'defaultMethod' => $customer->defaultPaymentMethod,
        ]);
    }
    
    /**
     * Mostrar formulario para agregar tarjeta
     */
    public function create()
    {
        $customer = auth()->user()->customer;
        
        if (!$customer) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'No se encontró tu perfil de cliente.']);
        }
        
        return Inertia::render('PaymentMethods/Create', [
            'customer' => $customer,
            'maxCards' => 10,
            'currentCount' => $customer->paymentMethods()->count(),
        ]);
    }
    
    /**
     * Guardar nueva tarjeta tokenizada
     */
    public function store(StorePaymentMethodRequest $request)
    {
        $customer = $request->user()->customer;
        
        if (!$customer) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'No se encontró tu perfil de cliente.']);
        }
        
        // Verificar límite de tarjetas
        if ($customer->paymentMethods()->count() >= 10) {
            return redirect()->route('payment-methods.index')
                ->withErrors(['card' => 'Has alcanzado el límite de tarjetas guardadas (10).']);
        }
        
        $result = $this->paymentMethodService->tokenizeCard(
            customer: $customer,
            cardData: [
                'number' => $request->card_number,
                'exp_month' => $request->exp_month,
                'exp_year' => $request->exp_year,
                'cvc' => $request->cvc,
                'type' => 'credit', // Puedes detectar si es débito
                'alias' => $request->alias,
                'cardholder_name' => $request->cardholder_name,
            ],
            metadata: [
                'source' => 'web_form',
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip(),
            ]
        );
        
        if (!$result['success']) {
            Log::error('Error tokenizando tarjeta', [
                'customer_id' => $customer->id,
                'error' => $result['error'],
            ]);
            
            return redirect()->back()
                ->withInput()
                ->withErrors(['card' => $result['error']]);
        }
        
        return redirect()->route('payment-methods.index')
            ->with('success', '✅ Tarjeta guardada exitosamente.');
    }
    
    /**
     * Mostrar formulario para editar tarjeta
     */
    public function edit(CustomerPaymentMethod $paymentMethod)
    {
        // Validar propiedad
        if (!$paymentMethod->belongsToUser(auth()->user())) {
            abort(403);
        }
        
        return Inertia::render('PaymentMethods/Edit', [
            'paymentMethod' => $paymentMethod,
        ]);
    }
    
    /**
     * Actualizar tarjeta (alias, estado default)
     */
    public function update(UpdatePaymentMethodRequest $request, CustomerPaymentMethod $paymentMethod)
    {
        // Validar propiedad (ya hecho en request)
        
        $result = $this->paymentMethodService->updateCard(
            $paymentMethod,
            $request->validated()
        );
        
        if (!$result['success']) {
            return redirect()->back()
                ->withErrors(['card' => $result['error']]);
        }
        
        return redirect()->route('payment-methods.index')
            ->with('success', '✅ Tarjeta actualizada.');
    }
    
    /**
     * Eliminar/desactivar tarjeta
     */
    public function destroy(Request $request, CustomerPaymentMethod $paymentMethod)
    {
        // Validar propiedad
        if (!$paymentMethod->belongsToUser(auth()->user())) {
            abort(403);
        }
        
        $permanent = $request->boolean('permanent', false);
        $result = $this->paymentMethodService->deleteCard($paymentMethod, $permanent);
        
        if (!$result['success']) {
            return redirect()->back()
                ->withErrors(['card' => $result['error']]);
        }
        
        $message = $permanent 
            ? '✅ Tarjeta eliminada permanentemente.'
            : '✅ Tarjeta desactivada.';
            
        return redirect()->route('payment-methods.index')
            ->with('success', $message);
    }
    
    /**
     * Establecer como tarjeta por defecto
     */
    public function setAsDefault(CustomerPaymentMethod $paymentMethod)
    {
        if (!$paymentMethod->belongsToUser(auth()->user())) {
            abort(403);
        }
        
        if (!$paymentMethod->can_be_used) {
            return redirect()->back()
                ->withErrors(['card' => 'Esta tarjeta no puede usarse como predeterminada.']);
        }
        
        $paymentMethod->markAsDefault();
        
        return redirect()->route('payment-methods.index')
            ->with('success', '✅ Tarjeta establecida como predeterminada.');
    }
    
    /**
     * API: Listar tarjetas (para frontend/React)
     */
    public function apiIndex(Request $request)
    {
        $customer = $request->user()->customer;
        
        if (!$customer) {
            return response()->json([
                'success' => false,
                'error' => 'Customer not found',
            ], 404);
        }
        
        $result = $this->paymentMethodService->listCustomerCards($customer);
        
        return response()->json([
            'success' => true,
            'data' => $result['cards'],
            'meta' => [
                'count' => $result['count'],
                'has_default' => $result['has_default'],
                'can_add_more' => $result['count'] < 10,
            ],
        ]);
    }
    
    /**
     * API: Obtener tarjeta específica
     */
    public function apiShow(CustomerPaymentMethod $paymentMethod)
    {
        if (!$paymentMethod->belongsToUser(auth()->user())) {
            return response()->json([
                'success' => false,
                'error' => 'Not authorized',
            ], 403);
        }
        
        return response()->json([
            'success' => true,
            'data' => $paymentMethod,
        ]);
    }
}