<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

use App\Models\LaboratoryBillingReportSchedule;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportRecipientNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryBillingReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(LaboratoryBillingAccess::class)->allowsReports($this->user());
    }

    protected function prepareForValidation(): void
    {
        $normalizer = app(LaboratoryBillingReportRecipientNormalizer::class);
        $filters = [
            'brand' => $this->input('brand'),
            'laboratory_store_id' => $this->input('laboratory_store_id'),
            'status' => $this->input('status'),
        ];

        $this->merge([
            'recipients' => $normalizer->normalize($this->input('recipients')),
            'weekdays' => collect($this->input('weekdays', []))->map(fn ($day) => (int) $day)->unique()->values()->all(),
            'filters' => collect($filters)->filter(fn ($value) => filled($value))->all(),
            'included_sections' => collect($this->input('included_sections', []))->filter()->unique()->values()->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['boolean'],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['required', 'string', Rule::in(['America/Monterrey'])],
            'period_type' => ['required', 'string', Rule::in(collect(LaboratoryBillingReportSchedule::periodOptions())->pluck('value')->all())],
            'recipients' => ['array'],
            'recipients.*' => ['email', 'max:255'],
            'included_sections' => ['array'],
            'included_sections.*' => ['string', Rule::in(collect(LaboratoryBillingReportSchedule::sectionOptions())->pluck('value')->all())],
            'include_excel' => ['boolean'],
            'brand' => ['nullable', 'string', 'max:50'],
            'laboratory_store_id' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value === '__none__') {
                    return;
                }

                if (! is_numeric($value) || ! \App\Models\LaboratoryStore::query()->whereKey((int) $value)->exists()) {
                    $fail('La sucursal seleccionada no es válida.');
                }
            }],
            'status' => ['nullable', 'string', Rule::in(['pending', 'in_progress', 'completed', 'overdue'])],
            'filters' => ['array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('is_active')) {
                if (empty($this->input('recipients'))) {
                    $validator->errors()->add('recipients', 'Agrega al menos un destinatario para activar la programación.');
                }
                if (empty($this->input('weekdays'))) {
                    $validator->errors()->add('weekdays', 'Selecciona al menos un día de envío.');
                }
                if (! filled($this->input('send_time'))) {
                    $validator->errors()->add('send_time', 'Selecciona una hora de envío.');
                }
            }
        });
    }
}
