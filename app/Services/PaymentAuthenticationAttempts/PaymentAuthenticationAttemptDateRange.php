<?php

namespace App\Services\PaymentAuthenticationAttempts;

use Carbon\CarbonImmutable;

class PaymentAuthenticationAttemptDateRange
{
    public const TIMEZONE = 'America/Monterrey';

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $startDate,
        public string $endDate,
        public string $period,
    ) {}

    public static function fromFilters(array $filters): self
    {
        $period = $filters['period'] ?? null;
        $tz = self::TIMEZONE;

        if (! in_array($period, ['1d', '7d', '30d', 'custom'], true)) {
            $period = filled($filters['start_date'] ?? null) || filled($filters['end_date'] ?? null)
                ? 'custom'
                : '7d';
        }

        if (in_array($period, ['1d', '7d', '30d'], true)) {
            $days = match ($period) {
                '1d' => 0,
                '7d' => 6,
                '30d' => 29,
            };

            $start = CarbonImmutable::now($tz)->subDays($days)->startOfDay();
            $end = CarbonImmutable::now($tz)->endOfDay();
        } else {
            $start = filled($filters['start_date'] ?? null)
                ? CarbonImmutable::parse($filters['start_date'], $tz)->startOfDay()
                : CarbonImmutable::now($tz)->subDays(6)->startOfDay();
            $end = filled($filters['end_date'] ?? null)
                ? CarbonImmutable::parse($filters['end_date'], $tz)->endOfDay()
                : CarbonImmutable::now($tz)->endOfDay();
            $period = 'custom';
        }

        $appTimezone = (string) config('app.timezone', 'UTC');

        return new self(
            $start->timezone($appTimezone),
            $end->timezone($appTimezone),
            $start->toDateString(),
            $end->toDateString(),
            $period,
        );
    }

    public function formattedStart(): string
    {
        return CarbonImmutable::parse($this->startDate, self::TIMEZONE)->isoFormat('D MMM Y');
    }

    public function formattedEnd(): string
    {
        return CarbonImmutable::parse($this->endDate, self::TIMEZONE)->isoFormat('D MMM Y');
    }

    public function label(): string
    {
        return $this->formattedStart().' – '.$this->formattedEnd().' (America/Monterrey)';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'formatted_start_date' => $this->formattedStart(),
            'formatted_end_date' => $this->formattedEnd(),
            'timezone' => self::TIMEZONE,
            'label' => $this->label(),
        ];
    }
}
