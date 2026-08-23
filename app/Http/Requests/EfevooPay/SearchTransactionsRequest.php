<?php

namespace App\Http\Requests\EfevooPay;

use Illuminate\Foundation\Http\FormRequest;

class SearchTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_id' => 'required|integer',
        ];
    }
}
