<?php

namespace App\Http\Requests\PaymentMethods;

use App\Models\EfevooToken;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPaymentMethodRequest extends FormRequest
{
    public function authorize()
    {
        $tokenId = $this->route('payment_method');
        $customerId = $this->user()->customer->id;
        
        return EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customerId)
            ->exists();
    }
    
    public function rules()
    {
        return [
            // No se necesitan reglas adicionales para eliminar
        ];
    }
    
    public function messages()
    {
        return [
            'authorize' => 'No tienes permiso para eliminar esta tarjeta.',
        ];
    }
}