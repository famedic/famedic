<?php

namespace App\Support\ActiveCampaign;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Filtros del Dashboard Ejecutivo de Marketing Intelligence.
 */
final class ActiveCampaignDashboardFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
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
            : $endLocal->copy()->subDays(6)->startOfDay();

        if ($startLocal->greaterThan($endLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfDay(), $startLocal->copy()->endOfDay()];
        }

        // Cap máximo 90 días para proteger performance.
        if ($startLocal->diffInDays($endLocal) > 89) {
            $startLocal = $endLocal->copy()->subDays(89)->startOfDay();
        }

        $daySpan = max(1, $startLocal->diffInDays($endLocal) + 1);
        $previousEndLocal = $startLocal->copy()->subSecond();
        $previousStartLocal = $previousEndLocal->copy()->subDays($daySpan - 1)->startOfDay();

        return new self(
            start: $startLocal->copy()->utc(),
            end: $endLocal->copy()->utc(),
            previousStart: $previousStartLocal->copy()->utc(),
            previousEnd: $previousEndLocal->copy()->utc(),
            startLocal: $startLocal,
            endLocal: $endLocal,
            bustCache: $request->boolean('refresh'),
        );
    }

    /**
     * @return array{start_date: string, end_date: string}
     */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startLocal->toDateString(),
            'end_date' => $this->endLocal->toDateString(),
        ];
    }

    public function cacheKey(string $suffix = 'overview'): string
    {
        return 'mi-dash:v1:'.sha1(json_encode($this->toArray()).'|'.$suffix);
    }
}
