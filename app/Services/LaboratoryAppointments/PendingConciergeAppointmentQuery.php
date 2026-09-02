<?php

namespace App\Services\LaboratoryAppointments;

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Services\Carts\CartUserActivityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingConciergeAppointmentQuery
{
    private const APPOINTMENT_ALIAS = 'laboratory_appointments';

    public function apply(
        Builder $query,
        string $pendingSort = 'priority',
        ?string $priorityFilter = null,
    ): Builder {
        $pendingSort = in_array($pendingSort, ['priority', 'oldest', 'newest'], true)
            ? $pendingSort
            : 'priority';

        if ($pendingSort === 'priority') {
            $this->addPriorityComputation($query);
            $this->applyPriorityFilter($query, $priorityFilter);

            return $query
                ->orderBy('pending_priority_tier')
                ->orderByDesc('pending_last_user_activity_at')
                ->orderBy(self::APPOINTMENT_ALIAS.'.created_at')
                ->orderBy(self::APPOINTMENT_ALIAS.'.id');
        }

        if ($pendingSort === 'newest') {
            return $query
                ->orderByDesc(self::APPOINTMENT_ALIAS.'.created_at')
                ->orderByDesc(self::APPOINTMENT_ALIAS.'.id');
        }

        return $query
            ->orderBy(self::APPOINTMENT_ALIAS.'.created_at')
            ->orderBy(self::APPOINTMENT_ALIAS.'.id');
    }

    public function priorityTierExpressionSql(): string
    {
        $resolvedCartSql = $this->resolvedCartIdSql();
        $lastActivitySql = $this->lastActivityForResolvedCartSql($resolvedCartSql);

        return $this->priorityTierSql($lastActivitySql, $resolvedCartSql);
    }

    private function addPriorityComputation(Builder $query): void
    {
        $query->select(self::APPOINTMENT_ALIAS.'.*');

        $resolvedCartSql = $this->resolvedCartIdSql();
        $lastActivitySql = $this->lastActivityForResolvedCartSql($resolvedCartSql);
        $tierSql = $this->priorityTierSql($lastActivitySql, $resolvedCartSql);

        $query->addSelect([
            DB::raw("({$resolvedCartSql}) as pending_resolved_cart_id"),
            DB::raw("({$lastActivitySql}) as pending_last_user_activity_at"),
            DB::raw("({$tierSql}) as pending_priority_tier"),
        ]);
    }

    private function applyPriorityFilter(Builder $query, ?string $priorityFilter): void
    {
        if (! in_array($priorityFilter, ['recent', 'active_cart', 'without_recent_activity'], true)) {
            return;
        }

        $tierSql = $this->priorityTierExpressionSql();

        match ($priorityFilter) {
            'recent' => $query->whereRaw("({$tierSql}) = 1"),
            'active_cart' => $query->whereRaw("({$tierSql}) <= 3"),
            'without_recent_activity' => $query->whereRaw("({$tierSql}) = 4"),
        };
    }

    private function resolvedCartIdSql(): string
    {
        $la = self::APPOINTMENT_ALIAS;
        $labType = MonitoringCartType::Lab->value;
        $activeStatus = MonitoringCartStatus::Active->value;

        $legacyCart = $this->legacyUniqueCartIdSql($la, $labType, $activeStatus);

        if (! $this->hasCartIdColumn()) {
            return $legacyCart;
        }

        $brandMatch = $this->cartBrandMatchesAppointmentBrandSql('c', $la);
        $directCart = "SELECT CASE
            WHEN {$la}.cart_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM carts c
                INNER JOIN customers cu ON cu.id = {$la}.customer_id AND cu.user_id = c.user_id
                WHERE c.id = {$la}.cart_id
                    AND c.type = '{$labType}'
                    AND c.status = '{$activeStatus}'
                    AND {$brandMatch}
            ) THEN {$la}.cart_id
            ELSE NULL
        END";

        return "COALESCE(({$directCart}), ({$legacyCart}))";
    }

    private function legacyUniqueCartIdSql(string $appointmentAlias, string $labType, string $activeStatus): string
    {
        if (! Schema::hasTable('carts') || ! Schema::hasTable('customers')) {
            return 'SELECT NULL WHERE 0 = 1';
        }

        $cartIdNullGuard = $this->hasCartIdColumn()
            ? "AND {$appointmentAlias}.cart_id IS NULL"
            : '';

        $candidateWhere = function (string $cartAlias) use ($appointmentAlias, $labType, $activeStatus, $cartIdNullGuard): string {
            $brandMatch = $this->cartBrandMatchesAppointmentBrandSql($cartAlias, $appointmentAlias);
            $windowSql = $this->appointmentWithinCartWindowSql($cartAlias, $appointmentAlias);

            return "{$cartAlias}.type = '{$labType}'
                AND {$cartAlias}.status = '{$activeStatus}'
                AND {$brandMatch}
                AND {$windowSql}
                {$cartIdNullGuard}";
        };

        $whereLc = $candidateWhere('lc');
        $whereLc2 = $candidateWhere('lc2');

        return "SELECT lc.id
            FROM carts lc
            INNER JOIN customers lcust ON lcust.id = {$appointmentAlias}.customer_id AND lcust.user_id = lc.user_id
            WHERE {$whereLc}
                AND (
                    SELECT COUNT(*)
                    FROM carts lc2
                    INNER JOIN customers lcust2 ON lcust2.id = {$appointmentAlias}.customer_id AND lcust2.user_id = lc2.user_id
                    WHERE {$whereLc2}
                ) = 1
            LIMIT 1";
    }

    private function cartBrandMatchesAppointmentBrandSql(string $cartAlias, string $appointmentAlias): string
    {
        if (! Schema::hasTable('cart_items') || ! Schema::hasTable('laboratory_tests')) {
            return '1 = 1';
        }

        return "EXISTS (
            SELECT 1
            FROM cart_items ci
            INNER JOIN laboratory_tests lt ON lt.id = ci.product_id
            WHERE ci.cart_id = {$cartAlias}.id
                AND lt.brand = {$appointmentAlias}.brand
        )";
    }

    private function appointmentWithinCartWindowSql(string $cartAlias, string $appointmentAlias): string
    {
        $driver = DB::connection()->getDriverName();

        $windowEnd = match ($driver) {
            'sqlite' => "datetime(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), '+1 day')",
            'pgsql' => "COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at) + INTERVAL '1 day'",
            default => "DATE_ADD(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), INTERVAL 1 DAY)",
        };

        return "{$appointmentAlias}.created_at >= {$cartAlias}.created_at
            AND {$appointmentAlias}.created_at <= {$windowEnd}";
    }

    private function lastActivityForResolvedCartSql(string $resolvedCartSql): string
    {
        if (! Schema::hasTable('carts')) {
            return 'SELECT NULL WHERE 0 = 1';
        }

        $activitySql = CartUserActivityResolver::lastUserActivityAtSql('rc', allowUpdatedAtFallback: false);

        return "SELECT {$activitySql}
            FROM carts rc
            WHERE rc.id = ({$resolvedCartSql})
            LIMIT 1";
    }

    private function priorityTierSql(string $lastActivitySql, string $resolvedCartSql): string
    {
        $hours24Ago = $this->intervalAgoSql(24, 'hour');
        $days7Ago = $this->intervalAgoSql(7, 'day');

        return "CASE
            WHEN ({$resolvedCartSql}) IS NULL THEN 4
            WHEN ({$lastActivitySql}) IS NULL THEN 4
            WHEN ({$lastActivitySql}) >= {$hours24Ago} THEN 1
            WHEN ({$lastActivitySql}) >= {$days7Ago} THEN 2
            ELSE 3
        END";
    }

    private function intervalAgoSql(int $amount, string $unit): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "datetime('now', '-{$amount} {$unit}s')",
            'pgsql' => "NOW() - INTERVAL '{$amount} {$unit}s'",
            default => "DATE_SUB(NOW(), INTERVAL {$amount} {$unit})",
        };
    }

    private function hasCartIdColumn(): bool
    {
        return Schema::hasColumn('laboratory_appointments', 'cart_id');
    }
}
