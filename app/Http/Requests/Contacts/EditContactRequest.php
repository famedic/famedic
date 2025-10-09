<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

class EditContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->contact);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
