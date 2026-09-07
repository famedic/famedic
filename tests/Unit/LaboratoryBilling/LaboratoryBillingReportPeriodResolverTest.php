<?php

use App\Models\LaboratoryBillingReportSchedule;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use Illuminate\Support\Carbon;

function resolvedPeriodRange(string $period, string $now): array
{
    $resolved = app(LaboratoryBillingReportPeriodResolver::class)->resolve(
        $period,
        Carbon::parse($now, LaboratoryBillingReportPeriodResolver::TIMEZONE),
    );

    return [
        $resolved['start']->toDateTimeString(),
        $resolved['end']->toDateTimeString(),
        $resolved['start_utc']->toDateTimeString(),
        $resolved['end_utc']->toDateTimeString(),
    ];
}

it('resuelve ventanas moviles de dias incluyendo el dia actual completo', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-18 14:35:00', LaboratoryBillingReportPeriodResolver::TIMEZONE));

    expect(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_15_DAYS, now()))
        ->toBe(['2026-09-04 00:00:00', '2026-09-18 23:59:59', '2026-09-04 06:00:00', '2026-09-19 05:59:59'])
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_30_DAYS, now()))
        ->toBe(['2026-08-20 00:00:00', '2026-09-18 23:59:59', '2026-08-20 06:00:00', '2026-09-19 05:59:59'])
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_60_DAYS, now())[0])
        ->toBe('2026-07-21 00:00:00')
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_90_DAYS, now())[0])
        ->toBe('2026-06-21 00:00:00');
});

it('resuelve los ultimos meses completos excluyendo el mes actual', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-18 14:35:00', LaboratoryBillingReportPeriodResolver::TIMEZONE));

    expect(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_2_COMPLETE_MONTHS, now()))
        ->toBe(['2026-07-01 00:00:00', '2026-08-31 23:59:59', '2026-07-01 06:00:00', '2026-09-01 05:59:59'])
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_3_COMPLETE_MONTHS, now()))
        ->toBe(['2026-06-01 00:00:00', '2026-08-31 23:59:59', '2026-06-01 06:00:00', '2026-09-01 05:59:59']);
});

it('maneja cambio de ano febrero bisiesto y limites de mes en America Monterrey', function () {
    expect(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_2_COMPLETE_MONTHS, '2027-01-10 08:00:00')[0])
        ->toBe('2026-11-01 00:00:00')
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_2_COMPLETE_MONTHS, '2027-01-10 08:00:00')[1])
        ->toBe('2026-12-31 23:59:59')
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_3_COMPLETE_MONTHS, '2024-03-15 08:00:00')[0])
        ->toBe('2023-12-01 00:00:00')
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_LAST_3_COMPLETE_MONTHS, '2024-03-15 08:00:00')[1])
        ->toBe('2024-02-29 23:59:59')
        ->and(resolvedPeriodRange(LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_MONTH, '2026-03-31 08:00:00')[1])
        ->toBe('2026-02-28 23:59:59');
});

it('resuelve rango personalizado inclusivo en zona local', function () {
    $resolved = app(LaboratoryBillingReportPeriodResolver::class)->resolve(
        LaboratoryBillingReportSchedule::PERIOD_CUSTOM_RANGE,
        Carbon::parse('2026-09-18 14:35:00', LaboratoryBillingReportPeriodResolver::TIMEZONE),
        '2026-07-01',
        '2026-08-31',
    );

    expect($resolved['start']->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($resolved['end']->toDateTimeString())->toBe('2026-08-31 23:59:59')
        ->and(app(LaboratoryBillingReportPeriodResolver::class)->customDayCount('2026-07-01', '2026-08-31'))->toBe(62);
});
