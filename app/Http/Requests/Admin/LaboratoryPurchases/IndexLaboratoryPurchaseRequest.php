<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use App\Models\LaboratoryPurchase;
use Illuminate\Foundation\Http\FormRequest;

class IndexLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LaboratoryPurchase::class);
    }

    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'deleted' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today'],
            'invoice_requested' => ['nullable', 'string'],
            'invoice_uploaded' => ['nullable', 'string'],
            'results_uploaded' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'in:,odessa,stripe'],
            'brand' => ['nullable', 'string'],
        ];

        if ($this->filled('start_date') && $this->filled('end_date')) {
            $rules['start_date'][] = 'before_or_equal:end_date';
            $rules['end_date'][] = 'after_or_equal:start_date';
        }

        return $rules;
    }
}
