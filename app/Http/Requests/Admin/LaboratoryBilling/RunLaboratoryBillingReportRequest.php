<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RunLaboratoryBillingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(LaboratoryBillingAccess::class)->allowsReports($this->user());
    }

    public function rules(): array
    {
        $periods = collect($this->routeIs('admin.laboratory-billing.automatic-reports.test')
            ? \App\Models\LaboratoryBillingReportSchedule::periodOptions()
            : \App\Models\LaboratoryBillingReportSchedule::manualPeriodOptions())
            ->pluck('value')
            ->all();

        return [
            'period_type' => ['nullable', 'string', Rule::in($periods)],
            'custom_from' => ['nullable', 'required_if:period_type,custom_range', 'date_format:Y-m-d'],
            'custom_to' => ['nullable', 'required_if:period_type,custom_range', 'date_format:Y-m-d', 'after_or_equal:custom_from'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('period_type') !== 'custom_range') {
                return;
            }

            if ($this->routeIs('admin.laboratory-billing.automatic-reports.test')) {
                $validator->errors()->add('period_type', 'El rango personalizado solo está disponible para ejecuciones manuales.');

                return;
            }

            if ($validator->errors()->has('custom_from') || $validator->errors()->has('custom_to')) {
                return;
            }

            $timezone = LaboratoryBillingReportPeriodResolver::TIMEZONE;
            $from = Carbon::createFromFormat('Y-m-d', (string) $this->input('custom_from'), $timezone)?->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', (string) $this->input('custom_to'), $timezone)?->endOfDay();
            $today = now($timezone)->endOfDay();

            if ($from->isFuture() || $to->gt($today)) {
                $validator->errors()->add('custom_to', 'El rango personalizado no puede incluir fechas futuras.');
            }

            if ($from->diffInDays($to) + 1 > 366) {
                $validator->errors()->add('custom_to', 'El rango personalizado no puede exceder 366 días.');
            }
        });
    }
}
