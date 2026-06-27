<?php

namespace App\Http\Requests\Admin\Users;

use App\Data\StatesMexico;
use App\Enums\Gender;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator?->hasPermissionTo('users.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var User $targetUser */
        $targetUser = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'paternal_lastname' => ['required', 'string', 'max:255'],
            'maternal_lastname' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'state' => ['nullable', 'string', 'size:2', 'in:'.implode(',', StatesMexico::claves())],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($targetUser->id),
            ],
            'phone' => [
                'required',
                'phone',
                Rule::unique(User::class)->ignore($targetUser->id),
            ],
            'phone_country' => ['required', 'string', 'size:2'],
        ];
    }
}
