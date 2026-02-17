<?php
// app/Http/Requests/EfevooPay/RefundRequest.php

namespace App\Http\Requests\EfevooPay;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    
    public function rules(): array
    {
        return [
            'transaction_id' => 'required|integer|exists:efevoo_transactions,id',
        ];
    }
}