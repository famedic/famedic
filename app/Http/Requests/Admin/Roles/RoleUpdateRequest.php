<?php

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Role;
use App\Rules\AtLeastOneRoleHasAdministratorsAndRolesPermission;

class RoleUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route()->role);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(Role::class)->ignore($this->route()->role->id)],
            'permissions' => ['required', 'array', 'min:1', new AtLeastOneRoleHasAdministratorsAndRolesPermission($this->route()->role)],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
