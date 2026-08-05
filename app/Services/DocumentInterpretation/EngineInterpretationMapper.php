<?php

namespace App\Services\DocumentInterpretation;

/**
 * Maps the stable Vision JSON contract into the payload shape
 * expected by ClinicalMatchingEngine::build() — without modifying the Engine.
 */
class EngineInterpretationMapper
{
    /**
     * @param  array<string, mixed>  $contract
     * @param  array{filename?: string, mime?: string, pages?: int, preview_url?: string|null}  $documentMeta
     * @return array<string, mixed>
     */
    public function toEngineFormat(array $contract, array $documentMeta = []): array
    {
        $confidence = is_array($contract['confidence'] ?? null) ? $contract['confidence'] : [];

        $patient = $contract['patient'] ?? null;
        $doctor = $contract['doctor'] ?? null;

        $medications = [];
        foreach (array_values($contract['medications'] ?? []) as $index => $med) {
            if (! is_array($med) && ! is_string($med)) {
                continue;
            }
            if (is_string($med)) {
                $med = ['name' => $med];
            }
            $name = trim((string) ($med['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $medications[] = [
                'id' => 'det-med-'.($index + 1),
                'detected_name' => $name,
                'dose' => $med['dose'] ?? null,
                'frequency' => $med['frequency'] ?? null,
                'duration' => $med['duration'] ?? null,
                'route' => $med['route'] ?? null,
                'confidence' => $this->confidenceValue($confidence['medications'] ?? null, 0.7),
                'status' => $this->statusFromConfidence($confidence['medications'] ?? null),
            ];
        }

        $studies = [];
        foreach (array_values($contract['laboratory_tests'] ?? []) as $index => $test) {
            if (! is_array($test) && ! is_string($test)) {
                continue;
            }
            if (is_string($test)) {
                $test = ['name' => $test];
            }
            $name = trim((string) ($test['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $studies[] = [
                'id' => 'det-lab-'.($index + 1),
                'detected_name' => $name,
                'notes' => $test['notes'] ?? null,
                'confidence' => $this->confidenceValue($confidence['laboratory_tests'] ?? null, 0.7),
                'status' => $this->statusFromConfidence($confidence['laboratory_tests'] ?? null),
            ];
        }

        $instructions = $contract['instructions'] ?? [];
        if (is_string($instructions)) {
            $instructions = [$instructions];
        }
        $observations = $contract['observations'] ?? [];
        if (is_string($observations)) {
            $observations = [$observations];
        }
        $warnings = $contract['warnings'] ?? [];
        if (is_string($warnings)) {
            $warnings = [$warnings];
        }

        $diagnosisValue = $contract['diagnosis'] ?? null;
        if (is_array($diagnosisValue)) {
            $diagnosisValue = $diagnosisValue['value'] ?? ($diagnosisValue['name'] ?? json_encode($diagnosisValue));
        }

        $patientName = is_array($patient) ? ($patient['name'] ?? null) : null;
        $doctorName = is_array($doctor) ? ($doctor['name'] ?? null) : null;

        return [
            'session_id' => 'aci-vision-'.uniqid(),
            'document' => [
                'filename' => $documentMeta['filename'] ?? 'receta.jpg',
                'mime' => $documentMeta['mime'] ?? 'image/jpeg',
                'pages' => $documentMeta['pages'] ?? 1,
                'preview_url' => $documentMeta['preview_url'] ?? null,
                'placeholder' => empty($documentMeta['preview_url']),
            ],
            'patient' => [
                'name' => $patientName,
                'age' => is_array($patient) ? ($patient['age'] ?? null) : null,
                'sex' => is_array($patient) ? ($patient['sex'] ?? null) : null,
                'confidence' => $this->confidenceValue($confidence['patient'] ?? null, 0.6),
                'status' => $patientName
                    ? $this->statusFromConfidence($confidence['patient'] ?? null)
                    : 'needs_review',
            ],
            'physician' => [
                'name' => $doctorName,
                'license' => is_array($doctor) ? ($doctor['license'] ?? null) : null,
                'specialty' => is_array($doctor) ? ($doctor['specialty'] ?? null) : null,
                'confidence' => $this->confidenceValue($confidence['doctor'] ?? null, 0.6),
                'status' => $doctorName
                    ? $this->statusFromConfidence($confidence['doctor'] ?? null)
                    : 'needs_review',
            ],
            'date' => [
                'value' => $contract['date'] ?? null,
                'confidence' => 0.7,
                'status' => ($contract['date'] ?? null) ? 'available' : 'needs_review',
            ],
            'diagnosis' => [
                'value' => $diagnosisValue,
                'confidence' => $this->confidenceValue($confidence['diagnosis'] ?? null, 0.55),
                'status' => $diagnosisValue
                    ? $this->statusFromConfidence($confidence['diagnosis'] ?? null)
                    : 'needs_review',
            ],
            'medications' => $medications,
            'studies' => $studies,
            'indications' => [
                'value' => $instructions !== [] ? implode(' ', array_map('strval', $instructions)) : null,
                'confidence' => 0.65,
                'status' => $instructions !== [] ? 'needs_review' : 'needs_review',
            ],
            'observations' => [
                'value' => trim(implode(' ', array_filter([
                    $observations !== [] ? implode(' ', array_map('strval', $observations)) : null,
                    $warnings !== [] ? 'Alertas: '.implode(' ', array_map('strval', $warnings)) : null,
                ]))) ?: null,
                'confidence' => 0.6,
                'status' => 'needs_review',
            ],
            'ocr_text' => null,
            'ai_json' => $contract,
            'warnings' => $warnings,
            'vision_confidence' => $confidence,
        ];
    }

    private function confidenceValue(mixed $value, float $fallback): float
    {
        if (is_numeric($value)) {
            return max(0, min(1, (float) $value));
        }

        return $fallback;
    }

    private function statusFromConfidence(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'needs_review';
        }

        return ((float) $value) >= 0.8 ? 'available' : 'needs_review';
    }
}
