<?php

namespace App\Support\CustomerIntelligence;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class CustomerJourneyFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
        public readonly ?string $search,
        public readonly ?string $accountType,
        public readonly ?string $compareMode,
        public readonly string $heatmapMetric,
        public readonly string $tab,
        public readonly ?int $page,
        public readonly bool $bustCache = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $tz = 'America/Monterrey';

        $compareMode = self::nullableString($request->input('compare_mode')) ?? 'period';
        if (! in_array($compareMode, ['period', 'month_vs_previous', '30_vs_90'], true)) {
            $compareMode = 'period';
        }

        [$startLocal, $endLocal, $previousStartLocal, $previousEndLocal] = match ($compareMode) {
            'month_vs_previous' => self::monthVsPrevious($tz),
            '30_vs_90' => self::thirtyVsNinety($tz),
            default => self::customOrDefault($request, $tz),
        };

        $heatmapMetric = $request->string('heatmap_metric')->toString();
        if (! in_array($heatmapMetric, ['registrations', 'logins', 'checkouts', 'purchases'], true)) {
            $heatmapMetric = 'purchases';
        }

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['overview', 'paths', 'usuarios', 'insights', 'ia'], true)) {
            $tab = 'overview';
        }

        return new self(
            start: $startLocal->copy()->utc(),
            end: $endLocal->copy()->utc(),
            previousStart: $previousStartLocal->copy()->utc(),
            previousEnd: $previousEndLocal->copy()->utc(),
            startLocal: $startLocal,
            endLocal: $endLocal,
            search: self::nullableString($request->input('search')),
            accountType: self::nullableString($request->input('type')),
            compareMode: $compareMode,
            heatmapMetric: $heatmapMetric,
            tab: $tab,
            page: $request->integer('page') ?: null,
            bustCache: $request->boolean('refresh'),
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private static function customOrDefault(Request $request, string $tz): array
    {
        $endLocal = $request->filled('end_date')
            ? Carbon::parse($request->string('end_date')->toString(), $tz)->endOfDay()
            : Carbon::now($tz)->endOfDay();

        $startLocal = $request->filled('start_date')
            ? Carbon::parse($request->string('start_date')->toString(), $tz)->startOfDay()
            : $endLocal->copy()->subDays(29)->startOfDay();

        if ($startLocal->greaterThan($endLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfDay(), $startLocal->copy()->endOfDay()];
        }

        $daySpan = max(1, $startLocal->diffInDays($endLocal) + 1);
        $previousEndLocal = $startLocal->copy()->subSecond();
        $previousStartLocal = $previousEndLocal->copy()->subDays($daySpan - 1)->startOfDay();

        return [$startLocal, $endLocal, $previousStartLocal, $previousEndLocal];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private static function monthVsPrevious(string $tz): array
    {
        $now = Carbon::now($tz);
        $startLocal = $now->copy()->startOfMonth();
        $endLocal = $now->copy()->endOfDay();
        $previousStartLocal = $startLocal->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEndLocal = $startLocal->copy()->subSecond();

        return [$startLocal, $endLocal, $previousStartLocal, $previousEndLocal];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private static function thirtyVsNinety(string $tz): array
    {
        $endLocal = Carbon::now($tz)->endOfDay();
        $startLocal = $endLocal->copy()->subDays(29)->startOfDay();
        $previousEndLocal = $startLocal->copy()->subSecond();
        $previousStartLocal = $previousEndLocal->copy()->subDays(89)->startOfDay();

        return [$startLocal, $endLocal, $previousStartLocal, $previousEndLocal];
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'start_date' => $this->startLocal->toDateString(),
            'end_date' => $this->endLocal->toDateString(),
            'search' => $this->search,
            'type' => $this->accountType,
            'compare_mode' => $this->compareMode,
            'heatmap_metric' => $this->heatmapMetric,
            'tab' => $this->tab,
            'page' => $this->page,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        $payload = $this->toArray();
        unset($payload['page'], $payload['tab']);

        return 'ci-journey:v1:'.sha1(json_encode($payload).'|'.$suffix);
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
