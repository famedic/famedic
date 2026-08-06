<?php

namespace App\Support\CustomerIntelligence;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class DormantCustomersFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly Carbon $startLocal,
        public readonly Carbon $endLocal,
        public readonly ?string $search,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $registrationSource,
        public readonly ?string $emailVerification,
        public readonly ?string $phoneVerification,
        public readonly ?string $referralStatus,
        public readonly ?string $accountType,
        public readonly ?string $daysBucket,
        public readonly string $granularity,
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

        $granularity = $request->string('granularity')->toString();
        if (! in_array($granularity, ['day', 'week', 'month', 'year'], true)) {
            $granularity = 'day';
        }

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['resumen', 'clientes', 'conversion', 'segmentacion', 'campanas', 'fuentes', 'ia'], true)) {
            $tab = 'resumen';
        }

        return new self(
            start: $startLocal->copy()->utc(),
            end: $endLocal->copy()->utc(),
            previousStart: $previousStartLocal->copy()->utc(),
            previousEnd: $previousEndLocal->copy()->utc(),
            startLocal: $startLocal,
            endLocal: $endLocal,
            search: self::nullableString($request->input('search')),
            city: self::nullableString($request->input('city')),
            state: self::nullableString($request->input('state')),
            registrationSource: self::nullableString($request->input('registration_source')),
            emailVerification: self::nullableString($request->input('email_verification')),
            phoneVerification: self::nullableString($request->input('phone_verification')),
            referralStatus: self::nullableString($request->input('referral_status')),
            accountType: self::nullableString($request->input('type')),
            daysBucket: self::nullableString($request->input('days_bucket')),
            granularity: $granularity,
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
            'city' => $this->city,
            'state' => $this->state,
            'registration_source' => $this->registrationSource,
            'email_verification' => $this->emailVerification,
            'phone_verification' => $this->phoneVerification,
            'referral_status' => $this->referralStatus,
            'type' => $this->accountType,
            'days_bucket' => $this->daysBucket,
            'granularity' => $this->granularity,
            'tab' => $this->tab,
            'page' => $this->page,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function cacheKey(string $suffix = 'full'): string
    {
        $payload = $this->toArray();
        unset($payload['page'], $payload['tab']);

        return 'ci-dormant:v1:'.sha1(json_encode($payload).'|'.$suffix);
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
