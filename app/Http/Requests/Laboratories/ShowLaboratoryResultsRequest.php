<?php

namespace App\Http\Requests\Laboratories;

use Illuminate\Foundation\Http\FormRequest;

class ShowLaboratoryResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
