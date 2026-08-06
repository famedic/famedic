<?php

namespace App\Support\CustomerIntelligence;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class CohortsFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
        public readonly ?string $accountType,
        public readonly ?string $source,
        public readonly ?string $state,
        public readonly ?string $city,
        public readonly ?string $gender,
        public readonly int $maxWeeks,
        public readonly int $maxCohorts,
        public readonly string $tab,
        public readonly bool $bustCache = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $tz = 'America/Monterrey';

        $endLocal = $request->filled('end_date')
            ? Carbon::parse($request->string('end_date')->toString(), $tz)->endOfDay()
            : Carbon::now($tz)->endOfDay();

        $startLocal = $request->filled('start_date')
            ? Carbon::parse($request->string('start_date')->toString(), $tz)->startOfDay()
            : $endLocal->copy()->subMonthsNoOverflow(5)->startOfMonth();

        if ($startLocal->greaterThan($endLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfMonth(), $startLocal->copy()->endOfDay()];
        }

        $daySpan = max(1, $startLocal->diffInDays($endLocal) + 1);
        $previousEndLocal = $startLocal->copy()->subSecond();
        $previousStartLocal = $previousEndLocal->copy()->subDays($daySpan - 1)->startOfDay();

        $maxWeeks = $request->integer('max_weeks') ?: 12;
        $maxWeeks = max(4, min(16, $maxWeeks));

        $maxCohorts = $request->integer('max_cohorts') ?: 6;
        $maxCohorts = max(3, min(12, $maxCohorts));

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['overview', 'retention', 'repeat', 'churn', 'ltv', 'ia'], true)) {
            $tab = 'overview';
        }

        return new self(
            start: $startLocal->copy()->utc(),
            end: $endLocal->copy()->utc(),
            previousStart: $previousStartLocal->copy()->utc(),
            previousEnd: $previousEndLocal->copy()->utc(),
            startLocal: $startLocal,
            endLocal: $endLocal,
            accountType: self::nullableString($request->input('type')),
            source: self::nullableString($request->input('source')),
            state: self::nullableString($request->input('state')),
            city: self::nullableString($request->input('city')),
            gender: self::nullableString($request->input('gender')),
            maxWeeks: $maxWeeks,
            maxCohorts: $maxCohorts,
            tab: $tab,
            bustCache: $request->boolean('refresh'),
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'start_date' => $this->startLocal->toDateString(),
            'end_date' => $this->endLocal->toDateString(),
            'type' => $this->accountType,
            'source' => $this->source,
            'state' => $this->state,
            'city' => $this->city,
            'gender' => $this->gender,
            'max_weeks' => $this->maxWeeks,
            'max_cohorts' => $this->maxCohorts,
            'tab' => $this->tab,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        $payload = $this->toArray();
        unset($payload['tab']);

        return 'ci-cohorts:v1:'.sha1(json_encode($payload).'|'.$suffix);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
