<?php

namespace App\Support\CustomerIntelligence;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class ReferralIntelligenceFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
        public readonly ?string $search,
        public readonly ?string $status,
        public readonly ?string $company,
        public readonly ?string $source,
        public readonly ?string $city,
        public readonly ?string $segment,
        public readonly ?string $accountType,
        public readonly string $granularity,
        public readonly string $tab,
        public readonly string $view,
        public readonly string $compareMode,
        public readonly ?int $page,
        public readonly ?int $drawerUserId,
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

        $compareMode = $request->string('compare_mode')->toString();
        if (! in_array($compareMode, ['period', 'month_vs_previous', '30_vs_90'], true)) {
            $compareMode = 'period';
        }

        if ($compareMode === 'month_vs_previous') {
            $endLocal = Carbon::now($tz)->endOfMonth()->min(Carbon::now($tz)->endOfDay());
            $startLocal = $endLocal->copy()->startOfMonth()->startOfDay();
            $previousStartLocal = $startLocal->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $previousEndLocal = $startLocal->copy()->subSecond();
        } elseif ($compareMode === '30_vs_90') {
            $endLocal = Carbon::now($tz)->endOfDay();
            $startLocal = $endLocal->copy()->subDays(29)->startOfDay();
            $previousEndLocal = $startLocal->copy()->subSecond();
            $previousStartLocal = $previousEndLocal->copy()->subDays(89)->startOfDay();
        } else {
            $daySpan = max(1, $startLocal->diffInDays($endLocal) + 1);
            $previousEndLocal = $startLocal->copy()->subSecond();
            $previousStartLocal = $previousEndLocal->copy()->subDays($daySpan - 1)->startOfDay();
        }

        $granularity = $request->string('granularity')->toString();
        if (! in_array($granularity, ['day', 'week', 'month'], true)) {
            $granularity = 'day';
        }

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['overview', 'inviters', 'leaderboard', 'insights', 'ia'], true)) {
            $tab = 'overview';
        }

        $view = $request->string('view')->toString();
        if (! in_array($view, ['table', 'cards'], true)) {
            $view = 'table';
        }

        return new self(
            start: $startLocal->copy()->utc(),
            end: $endLocal->copy()->utc(),
            previousStart: $previousStartLocal->copy()->utc(),
            previousEnd: $previousEndLocal->copy()->utc(),
            startLocal: $startLocal,
            endLocal: $endLocal,
            search: self::nullableString($request->input('search')),
            status: self::nullableString($request->input('status')),
            company: self::nullableString($request->input('company')),
            source: self::nullableString($request->input('source')),
            city: self::nullableString($request->input('city')),
            segment: self::nullableString($request->input('segment')),
            accountType: self::nullableString($request->input('type')),
            granularity: $granularity,
            tab: $tab,
            view: $view,
            compareMode: $compareMode,
            page: $request->integer('page') ?: null,
            drawerUserId: $request->filled('drawer_user_id') ? $request->integer('drawer_user_id') : null,
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
            'status' => $this->status,
            'company' => $this->company,
            'source' => $this->source,
            'city' => $this->city,
            'segment' => $this->segment,
            'type' => $this->accountType,
            'granularity' => $this->granularity,
            'tab' => $this->tab,
            'view' => $this->view,
            'compare_mode' => $this->compareMode !== 'period' ? $this->compareMode : null,
            'page' => $this->page,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        $payload = $this->toArray();
        unset($payload['page'], $payload['tab'], $payload['view']);

        return 'ci-referral:v1:'.sha1(json_encode($payload).'|'.$suffix);
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
