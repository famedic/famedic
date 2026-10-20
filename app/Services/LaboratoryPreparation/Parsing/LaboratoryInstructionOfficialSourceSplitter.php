<?php

namespace App\Services\LaboratoryPreparation\Parsing;

/**
 * Divide el bloque "Indicaciones fuente (texto original)" del Excel Casos_prueba en estudios.
 */
final class LaboratoryInstructionOfficialSourceSplitter
{
    /**
     * @return list<array{
     *     study_id: string,
     *     study_name: string,
     *     indications: string,
     *     appointment_required: bool|null,
     *     header_line: string
     * }>
     */
    public function split(string $caseId, string $sourceBlock): array
    {
        $sourceBlock = trim($sourceBlock);
        if ($sourceBlock === '') {
            return [];
        }

        $blocks = preg_split('/\n\s*\n/u', $sourceBlock) ?: [];
        $studies = [];
        $index = 0;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = preg_split('/\R/u', $block) ?: [];
            $headerLine = trim((string) array_shift($lines));
            $indications = trim(implode("\n", array_filter($lines, fn ($line) => trim((string) $line) !== '')));

            $studyName = $headerLine;
            $appointmentRequired = null;

            if (preg_match('/^(.+?)\s*\[[^\]]+\]\s*—\s*Cita:\s*(Si|Sí|No)\s*$/iu', $headerLine, $matches)) {
                $studyName = trim($matches[1]);
                $appointmentRequired = in_array(mb_strtolower($matches[2]), ['si', 'sí'], true);
            }

            $index++;
            $studies[] = [
                'study_id' => sprintf('%s-%02d', $caseId, $index),
                'study_name' => $studyName,
                'indications' => $indications,
                'appointment_required' => $appointmentRequired,
                'header_line' => $headerLine,
            ];
        }

        if ($studies === []) {
            $studies[] = [
                'study_id' => sprintf('%s-01', $caseId),
                'study_name' => $caseId,
                'indications' => $sourceBlock,
                'appointment_required' => null,
                'header_line' => '',
            ];
        }

        return $studies;
    }
}
