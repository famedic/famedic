<?php

namespace App\Http\Requests\Odessa;

use Illuminate\Foundation\Http\FormRequest;

class StoreOdessaUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->customer->has_regular_account;
    }

    public function rules(): array
    {
        return [];
    }
}
