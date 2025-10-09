<?php

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'paternal_lastname' => 'required|string|max:255',
            'maternal_lastname' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'phone' => 'required|phone|max:255|unique:' . User::class,
            'birth_date' => 'required|date|before:today',
            'gender' => ['required', Rule::enum(Gender::class)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'referrer_id' => 'nullable|exists:users,id',
        ];
    }
}
