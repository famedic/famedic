<?php

namespace App\Http\Requests\Admin\MedicalAttentionSubscriptions;

use App\Models\MedicalAttentionSubscription;
use Illuminate\Foundation\Http\FormRequest;

class IndexMedicalAttentionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', MedicalAttentionSubscription::class);
    }

    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'in:,odessa,stripe'],
        ];

        if ($this->filled('start_date') && $this->filled('end_date')) {
            $rules['start_date'][] = 'before_or_equal:end_date';
            $rules['end_date'][] = 'after_or_equal:start_date';
        }

        return $rules;
    }
}
