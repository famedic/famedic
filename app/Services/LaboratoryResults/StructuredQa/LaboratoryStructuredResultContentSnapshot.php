<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Models\LaboratoryResultReport;

final class LaboratoryStructuredResultContentSnapshot
{
    public static function compute(LaboratoryResultReport $report): string
    {
        $report->loadMissing('observations');

        $parts = [
            (string) $report->id,
            (string) $report->input_hash,
            (string) $report->observation_count,
            (string) $report->laboratory_result_version_id,
        ];

        foreach ($report->observations->sortBy('id') as $observation) {
            $parts[] = implode('|', [
                (string) $observation->id,
                (string) $observation->analyte_code,
                (string) $observation->numeric_value,
                (string) $observation->text_value,
                (string) ($observation->metadata['promotion_evaluation']['input_hash'] ?? ''),
            ]);
        }

        return hash('sha256', implode("\n", $parts));
    }
}
