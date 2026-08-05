<?php

namespace App\Support\CustomerIntelligence;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class CustomerHealthFilter
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
        public readonly ?string $source,
        public readonly ?string $state,
        public readonly ?string $city,
        public readonly ?string $healthBand,
        public readonly ?string $segment,
        public readonly string $sort,
        public readonly string $tab,
        public readonly ?int $page,
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
            : $endLocal->copy()->subDays(89)->startOfDay();

        if ($startLocal->greaterThan($endLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfDay(), $startLocal->copy()->endOfDay()];
        }

        $daySpan = max(1, $startLocal->diffInDays($endLocal) + 1);
        $previousEndLocal = $startLocal->copy()->subSecond();
        $previousStartLocal = $previousEndLocal->copy()->subDays($daySpan - 1)->startOfDay();

        $healthBand = self::nullableString($request->input('health_band'));
        if ($healthBand && ! in_array($healthBand, ['excellent', 'good', 'at_risk', 'critical', 'lost'], true)) {
            $healthBand = null;
        }

        $segment = self::nullableString($request->input('segment'));
        if ($segment && ! in_array($segment, [
            'premium', 'dormant', 'recoverable', 'lost', 'vip', 'high_value', 'high_risk', 'next_purchase', 'high_conversion',
        ], true)) {
            $segment = null;
        }

        $sort = $request->string('sort')->toString();
        if (! in_array($sort, ['health_desc', 'health_asc', 'ltv_desc', 'churn_desc', 'recent'], true)) {
            $sort = 'health_desc';
        }

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['overview', 'scores', 'predictive', 'segments', 'ia'], true)) {
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
            source: self::nullableString($request->input('source')),
            state: self::nullableString($request->input('state')),
            city: self::nullableString($request->input('city')),
            healthBand: $healthBand,
            segment: $segment,
            sort: $sort,
            tab: $tab,
            page: $request->integer('page') ?: null,
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
            'search' => $this->search,
            'type' => $this->accountType,
            'source' => $this->source,
            'state' => $this->state,
            'city' => $this->city,
            'health_band' => $this->healthBand,
            'segment' => $this->segment,
            'sort' => $this->sort,
            'tab' => $this->tab,
            'page' => $this->page,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        $payload = $this->toArray();
        unset($payload['page'], $payload['tab']);

        return 'ci-health:v1:'.sha1(json_encode($payload).'|'.$suffix);
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
