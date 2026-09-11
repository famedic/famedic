<?php

use App\Support\ServiceHoursEvaluator;
use Carbon\CarbonImmutable;

function monterreyAt(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'America/Monterrey');
}

function conciergeSchedule(): array
{
    return config('famedic.concierge.schedule_by_day');
}

test('support general availability uses America/Monterrey weekdays', function (string $datetime, bool $expected) {
    expect(ServiceHoursEvaluator::isAvailable(
        monterreyAt($datetime),
        'America/Monterrey',
        ServiceHoursEvaluator::supportGeneralScheduleByDay(),
    ))->toBe($expected);
})->with([
    'Monday 8:29 AM closed' => ['2026-09-07 08:29:00', false],
    'Monday 8:30 AM open' => ['2026-09-07 08:30:00', true],
    'Monday midday open' => ['2026-09-07 12:00:00', true],
    'Monday 5:59 PM open' => ['2026-09-07 17:59:00', true],
    'Monday 6:00 PM closed' => ['2026-09-07 18:00:00', false],
    'Saturday closed' => ['2026-09-05 10:00:00', false],
    'Sunday closed' => ['2026-09-06 10:00:00', false],
]);

test('concierge availability uses checkout schedule independently', function (string $datetime, bool $expected) {
    expect(ServiceHoursEvaluator::isAvailable(
        CarbonImmutable::parse($datetime, 'America/Mexico_City'),
        'America/Mexico_City',
        conciergeSchedule(),
    ))->toBe($expected);
})->with([
    'Monday 6:59 AM closed' => ['2026-09-07 06:59:00', false],
    'Monday 7:00 AM open' => ['2026-09-07 07:00:00', true],
    'Monday 7:59 PM open' => ['2026-09-07 19:59:00', true],
    'Monday 8:00 PM closed' => ['2026-09-07 20:00:00', false],
    'Saturday 7:59 AM closed' => ['2026-09-05 07:59:00', false],
    'Saturday 8:00 AM open' => ['2026-09-05 08:00:00', true],
    'Saturday 2:59 PM open' => ['2026-09-05 14:59:00', true],
    'Saturday 3:00 PM closed' => ['2026-09-05 15:00:00', false],
    'Sunday 1:59 PM open' => ['2026-09-06 13:59:00', true],
    'Sunday 2:00 PM closed' => ['2026-09-06 14:00:00', false],
]);

test('support and concierge differ on Monday morning', function () {
    $instant = monterreyAt('2026-09-07 07:30:00');

    $supportOpen = ServiceHoursEvaluator::isAvailable(
        $instant,
        'America/Monterrey',
        ServiceHoursEvaluator::supportGeneralScheduleByDay(),
    );

    $conciergeOpen = ServiceHoursEvaluator::isAvailable(
        $instant->timezone('America/Mexico_City'),
        'America/Mexico_City',
        conciergeSchedule(),
    );

    expect($supportOpen)->toBeFalse()
        ->and($conciergeOpen)->toBeTrue();
});

test('support general display lines use AM/PM format', function () {
    $lines = ServiceHoursEvaluator::buildSupportGeneralDisplayLines();

    expect($lines)->toBe([
        'Lunes a viernes: 8:30 AM a 6:00 PM',
        'Sábado: Cerrado',
        'Domingo: Cerrado',
    ]);
});

test('concierge display lines use AM/PM format', function () {
    $lines = ServiceHoursEvaluator::buildConciergeDisplayLines(conciergeSchedule());

    expect($lines)->toBe([
        'Lunes a viernes: 7:00 AM a 8:00 PM',
        'Sábado: 8:00 AM a 3:00 PM',
        'Domingo: 8:00 AM a 2:00 PM',
    ]);
});

test('closing hour is exclusive for support general', function () {
    expect(ServiceHoursEvaluator::isAvailable(
        monterreyAt('2026-09-07 17:59:59'),
        'America/Monterrey',
        ServiceHoursEvaluator::supportGeneralScheduleByDay(),
    ))->toBeTrue();
});
