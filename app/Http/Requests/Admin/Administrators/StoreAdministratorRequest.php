<?php

namespace App\Http\Requests\Admin\Administrators;

use App\Models\Administrator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Administrator::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'paternal_lastname' => ['required', 'string', 'max:255'],
            'maternal_lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'has_laboratory_concierge_account' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roles = $this->input('roles', []);

            if (in_array('autorizador', $roles, true) && ! $this->user()->can('assign-autorizador-role')) {
                $validator->errors()->add('roles', 'Solo un superadmin puede asignar el rol autorizador.');
            }
        });
    }
}
