<?php

namespace App\Http\Requests\Admin\ConfigMonitor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->administrator?->hasPermissionTo('config_monitor.manage_metadata');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('sort_order') === null || $this->input('sort_order') === '') {
            $this->merge(['sort_order' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('setting_groups', 'slug')->ignore($group),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
