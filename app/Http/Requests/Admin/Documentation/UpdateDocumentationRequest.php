<?php

namespace App\Http\Requests\Admin\Documentation;

use App\Models\Documentation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Documentation::class);
    }

    public function rules(): array
    {
        return [
            'terms_of_service' => ['sometimes', 'string'],
            'privacy_policy' => ['sometimes', 'string'],
        ];
    }
}
