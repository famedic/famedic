<?php

namespace App\Http\Requests\Admin\Administrators;

use App\Models\Administrator;
use Illuminate\Foundation\Http\FormRequest;

class IndexAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Administrator::class);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
