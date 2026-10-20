<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

final class FastingRestrictionCollector
{
    /**
     * @param  list<FastingHourInterval>  $intervals
     * @return list<array<string, mixed>>
     */
    public function collectFromIntervals(array $intervals): array
    {
        $restrictions = [];

        foreach ($intervals as $interval) {
            $value = $interval->requirement->normalizedValue;
            $kinds = $value['dietary_restrictions'] ?? null;

            if (! is_array($kinds) || $kinds === []) {
                continue;
            }

            $restrictions[] = [
                'kinds' => array_values(array_unique($kinds)),
                'study_id' => $interval->requirement->studyId,
                'study_name' => $interval->requirement->studyName,
                'source_span' => $interval->requirement->sourceSpan,
            ];
        }

        return $this->dedupeRestrictions($restrictions);
    }

    /**
     * @param  list<array<string, mixed>>  $restrictions
     * @return list<array<string, mixed>>
     */
    private function dedupeRestrictions(array $restrictions): array
    {
        $seen = [];
        $result = [];

        foreach ($restrictions as $restriction) {
            $key = implode(',', $restriction['kinds']).'|'.$restriction['source_span'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $restriction;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $left
     * @param  list<array<string, mixed>>  $right
     */
    public function restrictionsCompatible(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return true;
        }

        $leftKinds = collect($left)->pluck('kinds')->flatten()->unique()->sort()->values()->all();
        $rightKinds = collect($right)->pluck('kinds')->flatten()->unique()->sort()->values()->all();

        return $leftKinds === $rightKinds;
    }
}
