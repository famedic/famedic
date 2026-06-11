<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos enviados no son válidos.',
                422,
                $validator->errors()->toArray(),
            )
        );
    }
}
