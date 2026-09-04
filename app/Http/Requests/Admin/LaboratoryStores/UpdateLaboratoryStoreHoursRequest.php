<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLaboratoryStoreHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_store'));
    }

    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('hours', []) as $index => $hour) {
                $isClosed = filter_var($hour['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $opensAt = $hour['opens_at'] ?? null;
                $closesAt = $hour['closes_at'] ?? null;

                if ($isClosed && ($opensAt || $closesAt)) {
                    $validator->errors()->add("hours.{$index}.opens_at", 'Los horarios cerrados no deben tener hora de apertura o cierre.');
                }

                if (! $isClosed && (! $opensAt || ! $closesAt)) {
                    $validator->errors()->add("hours.{$index}.opens_at", 'La apertura y cierre son requeridos cuando el día está abierto.');
                }

                if (! $isClosed && $opensAt && $closesAt && $opensAt >= $closesAt) {
                    $validator->errors()->add("hours.{$index}.closes_at", 'La hora de cierre debe ser posterior a la apertura.');
                }
            }
        });
    }
}
