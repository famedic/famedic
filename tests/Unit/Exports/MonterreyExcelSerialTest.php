<?php

use App\Support\Exports\MonterreyExcelSerial;
use Carbon\Carbon;

it('converts utc instant to monterrey wall clock excel serial', function () {
    $utc = Carbon::parse('2026-08-21 20:32:00', 'UTC');
    $monterrey = Carbon::parse('2026-08-21 14:32:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::from($utc))->toBe(MonterreyExcelSerial::from($monterrey));
});

it('applies six hour offset between utc storage and monterrey export for instants', function () {
    $utc = Carbon::parse('2026-08-21 20:45:00', 'UTC');
    $expectedMonterrey = Carbon::parse('2026-08-21 14:45:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::from($utc))->toBe(MonterreyExcelSerial::from($expectedMonterrey));
});

it('exports operational appointment wall clock consistently', function () {
    $appointmentCreatedAt = Carbon::parse('2026-08-21 14:32:00', 'America/Monterrey');
    $appointmentDate = Carbon::parse('2026-08-22 09:15:00', 'America/Monterrey');
    $confirmedAt = Carbon::parse('2026-08-21 14:45:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::fromOperationalWallClock($appointmentCreatedAt))
        ->toBe(MonterreyExcelSerial::fromOperationalWallClock(Carbon::parse('2026-08-21 14:32:00', 'UTC')))
        ->and(MonterreyExcelSerial::fromOperationalWallClock($appointmentDate))
        ->toBe(MonterreyExcelSerial::fromOperationalWallClock(Carbon::parse('2026-08-22 09:15:00', 'UTC')))
        ->and(MonterreyExcelSerial::fromOperationalWallClock($confirmedAt))
        ->toBe(MonterreyExcelSerial::fromOperationalWallClock(Carbon::parse('2026-08-21 14:45:00', 'UTC')));
});

it('exports last user activity using monterrey timezone for utc instants', function () {
    $lastActivityUtc = Carbon::parse('2026-08-20 02:10:00', 'UTC');
    $lastActivityLocal = Carbon::parse('2026-08-19 20:10:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::from($lastActivityUtc))
        ->toBe(MonterreyExcelSerial::from($lastActivityLocal));
});

it('produces the same excel serial regardless of php default timezone', function () {
    $instant = Carbon::parse('2026-08-21 20:32:00', 'UTC');

    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $fromUtcProcess = MonterreyExcelSerial::from($instant);

    date_default_timezone_set('America/Los_Angeles');
    $fromPacificProcess = MonterreyExcelSerial::from($instant);

    date_default_timezone_set($previousTimezone);

    expect($fromUtcProcess)->toBe($fromPacificProcess);
});

it('respects historical monterrey offset when timezone data is available', function () {
    $summerUtc = Carbon::parse('2019-07-15 18:00:00', 'UTC');
    $summerLocal = Carbon::parse('2019-07-15 13:00:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::from($summerUtc))->toBe(MonterreyExcelSerial::from($summerLocal));

    $winterUtc = Carbon::parse('2019-01-15 19:00:00', 'UTC');
    $winterLocal = Carbon::parse('2019-01-15 13:00:00', 'America/Monterrey');

    expect(MonterreyExcelSerial::from($winterUtc))->toBe(MonterreyExcelSerial::from($winterLocal));
});

it('operational wall clock export is independent of php default timezone', function () {
    $date = Carbon::parse('2026-08-21 14:32:00', 'America/Monterrey');

    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $fromUtc = MonterreyExcelSerial::fromOperationalWallClock($date);
    date_default_timezone_set('America/Los_Angeles');
    $fromPacific = MonterreyExcelSerial::fromOperationalWallClock($date);
    date_default_timezone_set($previousTimezone);

    expect($fromUtc)->toBe($fromPacific);
});
