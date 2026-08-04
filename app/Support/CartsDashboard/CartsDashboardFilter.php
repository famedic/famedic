<?php

namespace App\Support\CartsDashboard;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class CartsDashboardFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
        public readonly ?string $type,
        public readonly ?string $brand,
        public readonly ?string $displayStatus,
        public readonly ?string $city,
        public readonly ?string $paymentMethod,
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
            : $endLocal->copy()->subDays(29)->startOfDay();

        if ($startLocal->greaterThan($endLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfDay(), $startLocal->copy()->endOfDay()];
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
            type: self::nullableString($request->input('type')),
            brand: self::nullableString($request->input('brand')),
            displayStatus: self::nullableString($request->input('display_status')),
            city: self::nullableString($request->input('city')),
            paymentMethod: self::nullableString($request->input('payment_method')),
            bustCache: $request->boolean('refresh'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'start_date' => $this->startLocal->toDateString(),
            'end_date' => $this->endLocal->toDateString(),
            'type' => $this->type,
            'brand' => $this->brand,
            'display_status' => $this->displayStatus,
            'city' => $this->city,
            'payment_method' => $this->paymentMethod,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        return 'carts-dash:v2:'.sha1(json_encode($this->toArray()).'|'.$suffix);
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
