<?php

namespace App\Http\Requests\Admin\ConfigMonitor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSettingRequest extends FormRequest
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
        $this->merge([
            'is_sensitive' => $this->boolean('is_sensitive'),
            'is_required' => $this->boolean('is_required'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'setting_group_id' => ['required', 'exists:setting_groups,id'],
            'env_key' => ['required', 'string', 'max:255', Rule::unique('settings', 'env_key'), 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'config_key' => ['required', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_sensitive' => ['boolean'],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
