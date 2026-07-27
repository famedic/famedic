<?php

namespace App\Services\Otp\Registration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only phone uniqueness preflight (P0-A5.7A / D1–D2).
 * Returns aggregate counts only — never phone/email/name values.
 */
final class PhoneUniquenessAuditor
{
    /**
     * @return array{
     *     users_total: int,
     *     users_with_phone: int,
     *     users_phone_null: int,
     *     users_phone_empty: int,
     *     literal_duplicate_groups: int,
     *     literal_users_in_dup_groups: int,
     *     literal_max_group: int,
     *     blocks_unique_index: bool
     * }
     */
    public function audit(): array
    {
        if (! Schema::hasTable('users')) {
            return [
                'users_total' => 0,
                'users_with_phone' => 0,
                'users_phone_null' => 0,
                'users_phone_empty' => 0,
                'literal_duplicate_groups' => 0,
                'literal_users_in_dup_groups' => 0,
                'literal_max_group' => 0,
                'blocks_unique_index' => false,
            ];
        }

        $usersTotal = (int) DB::table('users')->count();
        $withPhone = (int) DB::table('users')->whereNotNull('phone')->where('phone', '!=', '')->count();
        $nullPhone = (int) DB::table('users')->whereNull('phone')->count();
        $emptyPhone = (int) DB::table('users')->where('phone', '')->count();

        $literal = DB::table('users')
            ->select('phone_country', 'phone', DB::raw('COUNT(*) as c'))
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone_country', 'phone')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $groups = $literal->count();
        $usersIn = (int) $literal->sum('c');
        $max = $literal->isEmpty() ? 0 : (int) $literal->max('c');

        return [
            'users_total' => $usersTotal,
            'users_with_phone' => $withPhone,
            'users_phone_null' => $nullPhone,
            'users_phone_empty' => $emptyPhone,
            'literal_duplicate_groups' => $groups,
            'literal_users_in_dup_groups' => $usersIn,
            'literal_max_group' => $max,
            'blocks_unique_index' => $groups > 0,
        ];
    }
}
