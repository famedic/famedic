<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

class DestroyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('contact'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
