<?php

namespace App\Http\Requests\MedicalAttention;

use Illuminate\Foundation\Http\FormRequest;

class FreeMedicalAttentionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return !$this->user()->customer->medicalAttentionSubscriptions()->exists();
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
