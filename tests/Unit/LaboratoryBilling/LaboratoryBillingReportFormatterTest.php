<?php

use App\Models\LaboratoryBillingReportSchedule;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportFormatter;
use Illuminate\Support\Carbon;

it('formatea tiempo promedio de atencion de horas a dias horas y minutos', function (?float $hours, string $value, ?string $detail) {
    $formatted = app(LaboratoryBillingReportFormatter::class)->averageResponseDuration($hours);

    expect($formatted['value'])->toBe($value)
        ->and($formatted['detail'])->toBe($detail);
})->with([
    [334.52, '13 días 22 horas', '31 minutos adicionales'],
    [48.0, '2 días', null],
    [27.0, '1 día 3 horas', null],
    [24.0, '1 día', null],
    [8.0, '8 horas', null],
    [1.0, '1 hora', null],
    [0.5, '30 minutos', null],
    [null, 'Sin datos', null],
]);

it('formatea el periodo con etiqueta amigable y fechas reales', function () {
    $formatter = app(LaboratoryBillingReportFormatter::class);

    expect($formatter->periodName(LaboratoryBillingReportSchedule::PERIOD_LAST_30_DAYS))->toBe('Últimos 30 días')
        ->and($formatter->periodName(LaboratoryBillingReportSchedule::PERIOD_CUSTOM_RANGE))->toBe('Rango personalizado')
        ->and($formatter->periodDateLabel(
            Carbon::parse('2026-08-10 00:00:00', 'America/Monterrey'),
            Carbon::parse('2026-09-08 23:59:59', 'America/Monterrey'),
        ))->toBe('Del 10 de agosto de 2026 al 8 de septiembre de 2026');
});
