<?php
// app/Http/Requests/EfevooPay/SearchTransactionsRequest.php

namespace App\Http\Requests\EfevooPay;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    
    public function rules(): array
    {
        return [
            'transaction_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d H:i:s',
            'end_date' => [
                'nullable',
                'date_format:Y-m-d H:i:s',
                Rule::requiredIf($this->filled('start_date')),
                function ($attribute, $value, $fail) {
                    if ($this->filled('start_date') && $this->filled('end_date')) {
                        $start = strtotime($this->input('start_date'));
                        $end = strtotime($this->input('end_date'));
                        
                        if ($end < $start) {
                            $fail('La fecha final debe ser posterior a la fecha inicial');
                        }
                        
                        // Máximo 31 días de diferencia
                        $diff = ($end - $start) / (60 * 60 * 24);
                        if ($diff > 31) {
                            $fail('El rango de fechas no puede exceder 31 días');
                        }
                    }
                }
            ],
        ];
    }
}