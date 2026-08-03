<?php

namespace App\Services\LaboratoryBilling;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LaboratoryBillingDateRange
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function fromInput(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $tz = 'America/Monterrey';
        $now = Carbon::now($tz);

        if (! $from && ! $to) {
            return new self(
                $now->copy()->subDays($defaultDays)->startOfDay(),
                $now->copy()->endOfDay(),
            );
        }

        $fromDate = $from
            ? Carbon::parse($from, $tz)->startOfDay()
            : Carbon::parse($to, $tz)->subDays($defaultDays)->startOfDay();

        $toDate = $to
            ? Carbon::parse($to, $tz)->endOfDay()
            : Carbon::parse($from, $tz)->endOfDay();

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        return new self($fromDate, $toDate);
    }

    public function previous(): self
    {
        $days = max(1, (int) $this->from->diffInDays($this->to) + 1);

        return new self(
            $this->from->copy()->subDays($days)->startOfDay(),
            $this->from->copy()->subDay()->endOfDay(),
        );
    }

    public function daysSpan(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to) + 1);
    }

    /**
     * day | week | month
     */
    public function chartGranularity(): string
    {
        $days = $this->daysSpan();

        if ($days <= 31) {
            return 'day';
        }

        if ($days <= 120) {
            return 'week';
        }

        return 'month';
    }

    public function toFilterArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'formatted_from' => $this->from->isoFormat('MMM D, Y'),
            'formatted_to' => $this->to->isoFormat('MMM D, Y'),
        ];
    }

    public function contains(CarbonInterface $date): bool
    {
        $localized = \Illuminate\Support\Carbon::parse($date)->timezone('America/Monterrey');

        return $localized->betweenIncluded($this->from, $this->to);
    }
}
