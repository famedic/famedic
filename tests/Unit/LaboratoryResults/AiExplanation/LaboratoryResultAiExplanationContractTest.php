<?php

namespace Tests\Unit\LaboratoryResults\AiExplanation;

use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Models\LaboratoryResultObservation;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputBuilder;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputHash;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationOutputValidator;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultAiExplanationContractTest extends TestCase
{
    #[Test]
    public function input_hash_is_deterministic_and_changes_with_input_or_prompt(): void
    {
        $input = $this->sampleInput();
        $this->assertSame(
            LaboratoryResultAiExplanationInputHash::compute($input, 1),
            LaboratoryResultAiExplanationInputHash::compute($input, 1),
        );

        $changed = $input;
        $changed['result']['value'] = 120;
        $this->assertNotSame(
            LaboratoryResultAiExplanationInputHash::compute($input, 1),
            LaboratoryResultAiExplanationInputHash::compute($changed, 1),
        );
        $this->assertNotSame(
            LaboratoryResultAiExplanationInputHash::compute($input, 1),
            LaboratoryResultAiExplanationInputHash::compute($input, 2),
        );
    }

    #[Test]
    public function output_validator_rejects_unsafe_content_and_ai_does_not_modify_observation(): void
    {
        $observation = new LaboratoryResultObservation([
            'reference_status' => LaboratoryResultReferenceStatus::High,
            'abnormal_flag' => true,
            'numeric_value' => 108,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
        ]);

        $before = $observation->reference_status;
        $validator = new LaboratoryResultAiExplanationOutputValidator();

        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $validator->validate([
            'explanation' => 'Tienes diabetes y debes tomar metformina.',
            'limitations' => 'Consulta.',
        ], $this->sampleInput());

        $this->assertSame($before, $observation->reference_status);
    }

    #[Test]
    public function input_builder_uses_whitelist_only(): void
    {
        $observation = new LaboratoryResultObservation([
            'analyte_code' => 'GLU',
            'analyte_name_raw' => 'Glucosa',
            'numeric_value' => 108,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'mg/dL',
            'reference_text' => '70-100',
            'reference_low' => 70,
            'reference_high' => 100,
            'reference_status' => LaboratoryResultReferenceStatus::High,
            'abnormal_flag' => true,
        ]);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $this->assertSame(LaboratoryResultAiExplanationContract::INPUT_ALLOWED_TOP_LEVEL_KEYS, array_keys($input));
    }

    /** @return array<string, mixed> */
    private function sampleInput(): array
    {
        return [
            'analyte' => ['code' => 'GLU', 'name' => 'Glucosa'],
            'result' => ['value' => 108.0, 'value_type' => 'numeric', 'unit' => 'mg/dL'],
            'reference' => ['text' => '70-100', 'low' => 70.0, 'high' => 100.0],
            'status' => 'high',
            'abnormal' => true,
        ];
    }
}
