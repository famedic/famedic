<?php

namespace App\Http\Requests\Admin\Documentation;

use App\Models\Documentation;
use Illuminate\Foundation\Http\FormRequest;

class IndexDocumentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Documentation::class);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
