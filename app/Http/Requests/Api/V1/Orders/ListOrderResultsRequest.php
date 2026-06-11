<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListOrderResultsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'brand' => ['nullable', 'string', Rule::in(array_map(fn ($case) => $case->value, LaboratoryBrand::cases()))],
            'status' => ['nullable', 'string', Rule::in(['in_progress', 'sample_taken', 'results_ready', 'cancelled'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
