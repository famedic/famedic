<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartItems;

use Illuminate\Foundation\Http\FormRequest;

class DestroyLaboratoryCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('laboratory_cart_item'));
    }

    public function rules(): array
    {
        return [];
    }
}
