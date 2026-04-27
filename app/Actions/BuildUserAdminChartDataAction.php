<?php

namespace App\Actions;

use App\Models\CertificateAccount;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildUserAdminChartDataAction
{
    public function __invoke(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $tz = 'America/Monterrey';

        if (! $startDate) {
            $startDate = Carbon::now($tz)->subDays(29)->startOfDay();
        } else {
            $startDate = $startDate->copy()->setTimezone($tz)->startOfDay();
        }

        if (! $endDate) {
            $endDate = Carbon::now($tz)->endOfDay();
        } else {
            $endDate = $endDate->copy()->setTimezone($tz)->endOfDay();
        }

        if ($startDate->gt($endDate)) {
            $tmp = $startDate->copy();
            $startDate = $endDate->copy()->startOfDay();
            $endDate = $tmp->copy()->endOfDay();
        }

        $startUtc = $startDate->copy()->startOfDay()->utc();
        $endUtc = $endDate->copy()->endOfDay()->utc();

        $days = collect($this->generateDateRange($startDate, $endDate));
        $dayKeys = $days->map(fn (Carbon $d) => $d->format('Y-m-d'))->all();

        $buckets = [];
        foreach ($dayKeys as $key) {
            $buckets[$key] = [
                'registrations' => 0,
                'email_verified' => 0,
                'phone_verified' => 0,
                'regular' => 0,
                'odessa' => 0,
                'family' => 0,
                'certificate' => 0,
                'google_proxy' => 0,
            ];
        }

        $usersCreated = User::query()
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->with(['customer' => function ($q) {
                $q->select('id', 'user_id', 'customerable_type');
            }])
            ->get(['id', 'created_at', 'password']);

        foreach ($usersCreated as $user) {
            $dayKey = Carbon::parse($user->created_at)->timezone($tz)->format('Y-m-d');
            if (! array_key_exists($dayKey, $buckets)) {
                continue;
            }
            $buckets[$dayKey]['registrations']++;
            if ($user->password === null) {
                $buckets[$dayKey]['google_proxy']++;
            }
            $type = $user->customer?->customerable_type;
            if ($type === RegularAccount::class) {
                $buckets[$dayKey]['regular']++;
            } elseif ($type === OdessaAfiliateAccount::class) {
                $buckets[$dayKey]['odessa']++;
            } elseif ($type === FamilyAccount::class) {
                $buckets[$dayKey]['family']++;
            } elseif ($type === CertificateAccount::class) {
                $buckets[$dayKey]['certificate']++;
            }
        }

        $emailVerifiedDates = User::query()
            ->whereNotNull('email_verified_at')
            ->whereBetween('email_verified_at', [$startUtc, $endUtc])
            ->pluck('email_verified_at');

        foreach ($emailVerifiedDates as $dt) {
            $dayKey = Carbon::parse($dt)->timezone($tz)->format('Y-m-d');
            if (isset($buckets[$dayKey])) {
                $buckets[$dayKey]['email_verified']++;
            }
        }

        $phoneVerifiedDates = User::query()
            ->whereNotNull('phone_verified_at')
            ->whereBetween('phone_verified_at', [$startUtc, $endUtc])
            ->pluck('phone_verified_at');

        foreach ($phoneVerifiedDates as $dt) {
            $dayKey = Carbon::parse($dt)->timezone($tz)->format('Y-m-d');
            if (isset($buckets[$dayKey])) {
                $buckets[$dayKey]['phone_verified']++;
            }
        }

        $hasADateFromPreviousYears = $startDate->year !== $endDate->year;

        $dataPoints = $days->map(function (Carbon $localDate) use ($buckets, $hasADateFromPreviousYears, $tz) {
            $key = $localDate->format('Y-m-d');
            $localDate = $localDate->copy()->setTimezone($tz);

            return array_merge([
                'date' => $hasADateFromPreviousYears
                    ? $localDate->isoFormat('MMM D, Y')
                    : $localDate->isoFormat('MMM D'),
            ], $buckets[$key]);
        });

        return [
            'dataPoints' => $dataPoints->all(),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
        ];
    }

    protected function generateDateRange(Carbon $startDate, Carbon $endDate): Collection
    {
        return collect($startDate->toPeriod($endDate, '1 day'));
    }
}
