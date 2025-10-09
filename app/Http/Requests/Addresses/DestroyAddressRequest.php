<?php

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('address'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
