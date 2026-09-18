<?php

namespace App\Services\LaboratoryRequirements;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Support\LaboratoryRequirements\BranchHoursInfo;
use App\Support\LaboratoryRequirements\BranchMatchResult;
use App\Support\LaboratoryRequirements\BranchResolution;
use App\Support\LaboratoryRequirements\CartRequirementGroup;
use App\Support\LaboratoryRequirements\CartRequirements;
use App\Support\LaboratoryRequirements\GeoPoint;
use App\Support\LaboratoryRequirements\GroupMatchResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BranchResolver
{
    public const MATCH_EXACT = 'EXACT';

    public const MATCH_MAPPED = 'MAPPED';

    public const MATCH_CATEGORY = 'CATEGORY';

    public const MATCH_APPROXIMATE = 'APPROXIMATE';

    public const MATCH_UNKNOWN = 'UNKNOWN';

    public function resolve(
        CartRequirements $requirements,
        ?GeoPoint $location = null,
        ?CarbonInterface $date = null,
    ): BranchResolution {
        $date ??= CarbonImmutable::now('America/Mexico_City');
        $brandKeys = collect(array_keys($requirements->brands))
            ->reject(fn (string $brand) => $brand === '__unknown' || LaboratoryBrand::tryFrom($brand) === null)
            ->values();

        if ($requirements->groups === []) {
            return new BranchResolution([], reasons: ['no_requirements']);
        }

        if ($brandKeys->isEmpty()) {
            return new BranchResolution([], reasons: ['brand_unknown']);
        }

        $byBrand = [];
        $all = [];

        foreach ($brandKeys as $brandKey) {
            $brandGroups = $this->groupsForBrand($requirements, $brandKey);
            $stores = LaboratoryStore::query()
                ->where('brand', $brandKey)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->with(['capabilities', 'hours'])
                ->orderBy('name')
                ->get();

            $results = $stores
                ->map(fn (LaboratoryStore $store) => $this->matchStore($store, $brandGroups, $requirements, $location, $date))
                ->sortBy(fn (BranchMatchResult $result) => $this->rankKey($result))
                ->values()
                ->all();

            $byBrand[$brandKey] = $results;
            array_push($all, ...$results);
        }

        usort($all, fn (BranchMatchResult $left, BranchMatchResult $right) => $this->rankKey($left) <=> $this->rankKey($right));

        return new BranchResolution(
            branches: $all,
            brands: $byBrand,
            reasons: $requirements->isResolvable ? [] : ['requirements_unknown'],
        );
    }

    /**
     * @return array<int, CartRequirementGroup>
     */
    private function groupsForBrand(CartRequirements $requirements, string $brand): array
    {
        $keys = $requirements->brands[$brand]['groups'] ?? [];

        return collect($requirements->groups)
            ->filter(fn (CartRequirementGroup $group) => in_array($group->groupKey, $keys, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, CartRequirementGroup>  $groups
     */
    private function matchStore(
        LaboratoryStore $store,
        array $groups,
        CartRequirements $requirements,
        ?GeoPoint $location,
        CarbonInterface $date,
    ): BranchMatchResult {
        $storeCapabilities = $store->capabilities->pluck('slug')->all();
        $groupResults = array_map(fn (CartRequirementGroup $group) => $this->matchGroup($group, $storeCapabilities), $groups);
        $missing = collect($groupResults)->flatMap(fn (GroupMatchResult $group) => $group->missingCapabilities)->unique()->values()->all();
        $matched = collect($groupResults)->flatMap(fn (GroupMatchResult $group) => $group->matchedCapabilities)->unique()->values()->all();
        $allSatisfied = collect($groupResults)->every(fn (GroupMatchResult $group) => $group->isSatisfied);
        $compatible = $requirements->isResolvable && $allSatisfied && $groups !== [];
        $reasons = [];

        if (! $requirements->isResolvable) {
            $reasons[] = 'requirements_unknown';
        }

        if (! $allSatisfied) {
            $reasons[] = 'missing_required_capabilities';
        }

        if ($groups === []) {
            $reasons[] = 'no_brand_requirements';
        }

        return new BranchMatchResult(
            branch: $store,
            isCompatible: $compatible,
            matchLevel: $requirements->isResolvable ? $this->worstConfidence($groupResults) : self::MATCH_UNKNOWN,
            groups: $groupResults,
            matchedRequirements: $matched,
            missingRequirements: $missing,
            distanceKm: $this->distanceKm($store, $location),
            brand: $store->brand?->value,
            hours: $this->hoursInfo($store, $date),
            reasons: $reasons,
        );
    }

    private function matchGroup(CartRequirementGroup $group, array $storeCapabilities): GroupMatchResult
    {
        $required = collect($group->requirements)->pluck('capabilitySlug')->filter()->values();
        $matched = $required->intersect($storeCapabilities)->values()->all();

        if ($group->operator === StudyRequirementResolver::OPERATOR_ANY) {
            $isSatisfied = $matched !== [];
            $missing = $isSatisfied ? [] : $required->all();
        } else {
            $missing = $required->diff($storeCapabilities)->values()->all();
            $isSatisfied = $missing === [];
        }

        return new GroupMatchResult(
            groupKey: $group->groupKey,
            operator: $group->operator,
            required: $group->required,
            isSatisfied: $isSatisfied,
            matchedCapabilities: $matched,
            missingCapabilities: $missing,
            confidence: $this->worstValue($group->confidences),
            sources: $group->sources,
        );
    }

    /**
     * @param  array<int, GroupMatchResult>  $groups
     */
    private function worstConfidence(array $groups): string
    {
        if ($groups === []) {
            return self::MATCH_UNKNOWN;
        }

        return $this->worstValue(collect($groups)->pluck('confidence')->all());
    }

    private function worstValue(array $values): string
    {
        $order = [
            self::MATCH_EXACT => 0,
            self::MATCH_MAPPED => 1,
            self::MATCH_CATEGORY => 2,
            self::MATCH_APPROXIMATE => 3,
            self::MATCH_UNKNOWN => 4,
        ];

        return collect($values)
            ->sortByDesc(fn (string $value) => $order[$value] ?? $order[self::MATCH_UNKNOWN])
            ->first() ?? self::MATCH_UNKNOWN;
    }

    private function distanceKm(LaboratoryStore $store, ?GeoPoint $location): ?float
    {
        if ($location === null || $store->latitude === null || $store->longitude === null) {
            return null;
        }

        $lat1 = deg2rad($location->latitude);
        $lat2 = deg2rad((float) $store->latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad((float) $store->longitude - $location->longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return round(6371 * 2 * asin(min(1, sqrt($a))), 3);
    }

    private function hoursInfo(LaboratoryStore $store, CarbonInterface $date): BranchHoursInfo
    {
        $day = (int) $date->isoWeekday();
        $hours = $store->hours->firstWhere('day_of_week', $day);

        if ($hours === null) {
            return new BranchHoursInfo(null, null, null);
        }

        $summary = $hours->is_closed
            ? 'Cerrado'
            : substr((string) $hours->opens_at, 11, 5).'-'.substr((string) $hours->closes_at, 11, 5);

        $isOpenNow = null;
        if (! $hours->is_closed && $hours->opens_at !== null && $hours->closes_at !== null) {
            $opens = CarbonImmutable::parse($date->toDateString().' '.substr((string) $hours->opens_at, 11, 8), $date->timezone);
            $closes = CarbonImmutable::parse($date->toDateString().' '.substr((string) $hours->closes_at, 11, 8), $date->timezone);
            $isOpenNow = $date->betweenIncluded($opens, $closes);
        }

        return new BranchHoursInfo(
            isOpenNow: $isOpenNow,
            opensOnRequestedDate: ! $hours->is_closed,
            hoursSummary: $summary,
        );
    }

    private function rankKey(BranchMatchResult $result): array
    {
        $level = [
            self::MATCH_EXACT => 0,
            self::MATCH_MAPPED => 1,
            self::MATCH_CATEGORY => 2,
            self::MATCH_APPROXIMATE => 3,
            self::MATCH_UNKNOWN => 4,
        ][$result->matchLevel] ?? 4;

        $openRank = match ($result->hours->isOpenNow) {
            true => 0,
            false => 1,
            default => 2,
        };

        return [
            $result->isCompatible ? 0 : 1,
            $result->distanceKm ?? PHP_FLOAT_MAX,
            $level,
            -collect($result->groups)->where('isSatisfied', true)->count(),
            $openRank,
            $result->branch->name,
        ];
    }
}
