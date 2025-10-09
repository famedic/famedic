<?php

namespace App\Http\Requests\Admin\Roles;

use App\Rules\AtLeastOneRoleHasAdministratorsAndRolesPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RoleDestroyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route()->role);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $rule = new AtLeastOneRoleHasAdministratorsAndRolesPermission($this->route()->role);

                $fail = function ($message) use ($validator) {
                    $validator->errors()->add('permissions', $message);
                };

                $rule->validate('permissions', [], $fail);
            }
        ];
    }
}
