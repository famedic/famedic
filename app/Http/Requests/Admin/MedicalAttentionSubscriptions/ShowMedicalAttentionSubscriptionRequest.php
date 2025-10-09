<?php

namespace App\Http\Requests\Admin\MedicalAttentionSubscriptions;

use Illuminate\Foundation\Http\FormRequest;

class ShowMedicalAttentionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('medical_attention_subscription'));
    }

    public function rules(): array
    {
        return [];
    }
}
