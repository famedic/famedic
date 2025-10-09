<?php

namespace App\Http\Requests\Admin\MedicalAttentionSubscriptions;

use Illuminate\Foundation\Http\FormRequest;

class ExportMedicalAttentionSubscriptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('medical-attention-subscriptions.manage.export');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => 'nullable|in:,active,inactive',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_method' => ['nullable', 'in:,odessa,stripe'],
        ];
    }
}
