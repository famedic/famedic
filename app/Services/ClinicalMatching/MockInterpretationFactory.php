<?php

namespace App\Services\ClinicalMatching;

/**
 * Demo interpretation payload simulating AI Clinical Interpreter output.
 * No OpenAI / OCR calls — static structured fixture for the Matching Engine UI.
 */
class MockInterpretationFactory
{
    /**
     * @return array<string, mixed>
     */
    public function sample(): array
    {
        return [
            'session_id' => 'aci-demo-001',
            'document' => [
                'filename' => 'receta-demo.jpg',
                'mime' => 'image/jpeg',
                'pages' => 1,
                'preview_url' => null,
                'placeholder' => true,
            ],
            'patient' => [
                'name' => 'María Elena Gutiérrez López',
                'age' => 54,
                'confidence' => 0.91,
                'status' => 'available',
            ],
            'physician' => [
                'name' => 'Dr. Carlos Méndez Ruiz',
                'license' => 'CED. 5432109',
                'confidence' => 0.88,
                'status' => 'available',
            ],
            'date' => [
                'value' => '2026-07-28',
                'confidence' => 0.95,
                'status' => 'available',
            ],
            'diagnosis' => [
                'value' => 'Diabetes mellitus tipo 2 · Hipertensión arterial',
                'confidence' => 0.72,
                'status' => 'needs_review',
            ],
            'medications' => [
                [
                    'id' => 'det-med-1',
                    'detected_name' => 'Metformina 850 mg',
                    'dose' => '850 mg',
                    'frequency' => '1 cada 12 hrs',
                    'duration' => '30 días',
                    'confidence' => 0.94,
                    'status' => 'available',
                ],
                [
                    'id' => 'det-med-2',
                    'detected_name' => 'Losartán 50 mg',
                    'dose' => '50 mg',
                    'frequency' => '1 cada 24 hrs',
                    'duration' => '30 días',
                    'confidence' => 0.89,
                    'status' => 'available',
                ],
                [
                    'id' => 'det-med-3',
                    'detected_name' => 'Omeprazol',
                    'dose' => '20 mg',
                    'frequency' => '1 en ayunas',
                    'duration' => '14 días',
                    'confidence' => 0.81,
                    'status' => 'needs_review',
                ],
            ],
            'studies' => [
                [
                    'id' => 'det-lab-1',
                    'detected_name' => 'BH',
                    'confidence' => 0.96,
                    'status' => 'available',
                ],
                [
                    'id' => 'det-lab-2',
                    'detected_name' => 'QS6',
                    'confidence' => 0.97,
                    'status' => 'available',
                ],
                [
                    'id' => 'det-lab-3',
                    'detected_name' => 'Perfil Hormonal',
                    'confidence' => 0.64,
                    'status' => 'needs_review',
                ],
            ],
            'indications' => [
                'value' => 'Control metabólico. Acudir con resultados en 7 días.',
                'confidence' => 0.78,
                'status' => 'needs_review',
            ],
            'observations' => [
                'value' => 'Paciente refiere intolerancia gastrointestinal leve a metformina.',
                'confidence' => 0.7,
                'status' => 'needs_review',
            ],
            'ocr_text' => <<<'TXT'
Receta médica
Paciente: María Elena Gutiérrez López · 54 años
Dr. Carlos Méndez Ruiz · CED. 5432109
Fecha: 28/07/2026

Dx: DM2 · HTA

Rx:
1. Metformina 850 mg — 1 c/12 hrs × 30 días
2. Losartán 50 mg — 1 c/24 hrs × 30 días
3. Omeprazol 20 mg — 1 en ayunas × 14 días

Estudios:
- BH
- QS6
- Perfil Hormonal

Indicaciones: Control metabólico. Acudir con resultados en 7 días.
Obs: Intolerancia GI leve a metformina.
TXT,
            'ai_json' => null,
        ];
    }
}
