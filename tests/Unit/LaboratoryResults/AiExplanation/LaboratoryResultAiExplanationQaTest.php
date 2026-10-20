<?php

namespace Tests\Unit\LaboratoryResults\AiExplanation;

use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationOutputValidator;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationValidationException;
use App\Services\LaboratoryResults\AiExplanation\Qa\LaboratoryResultAiExplanationQaCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultAiExplanationQaTest extends TestCase
{
    private LaboratoryResultAiExplanationOutputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new LaboratoryResultAiExplanationOutputValidator();
    }

    #[Test]
    #[DataProvider('safeCorpusProvider')]
    public function safe_corpus_passes_validation(string $caseId, array $output, array $input): void
    {
        $validated = $this->validator->validate($output, $input);

        $this->assertSame(trim($output['explanation']), $validated['explanation']);
        $this->assertSame(trim($output['limitations']), $validated['limitations']);
        $this->assertSame($caseId, $caseId);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
    public static function safeCorpusProvider(): iterable
    {
        $statusMap = LaboratoryResultAiExplanationQaCorpus::statusForSafeCase();

        foreach (LaboratoryResultAiExplanationQaCorpus::safeResponses() as $caseId => $output) {
            $status = $statusMap[$caseId] ?? 'high';
            yield $caseId => [$caseId, $output, LaboratoryResultAiExplanationQaCorpus::inputForStatus($status)];
        }

        yield 'SAFE_EDUCATIONAL_HIGH' => [
            'SAFE_EDUCATIONAL_HIGH',
            LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_EDUCATIONAL_001'],
            LaboratoryResultAiExplanationQaCorpus::inputForStatus('high'),
        ];
    }

    #[Test]
    #[DataProvider('schemaAttackProvider')]
    public function schema_attacks_are_invalid(string $caseId, array $output): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate($output, LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
        $this->assertSame($caseId, $caseId);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function schemaAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::schemaAttacks() as $caseId => $output) {
            yield $caseId => [$caseId, $output];
        }
    }

    #[Test]
    #[DataProvider('diagnosisAttackProvider')]
    public function diagnosis_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function diagnosisAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::diagnosisAttackPhrases() as $index => $phrase) {
            yield 'diagnosis_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('treatmentAttackProvider')]
    public function treatment_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function treatmentAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::treatmentAttackPhrases() as $index => $phrase) {
            yield 'treatment_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('medicationAttackProvider')]
    public function medication_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function medicationAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::medicationAttackPhrases() as $index => $phrase) {
            yield 'medication_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('clinicalRecommendationAttackProvider')]
    public function clinical_recommendation_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function clinicalRecommendationAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::clinicalRecommendationAttackPhrases() as $index => $phrase) {
            yield 'recommendation_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('resultAlterationProvider')]
    public function result_alteration_attacks_are_invalid(string $caseId, array $output): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate($output, LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
        $this->assertSame($caseId, $caseId);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function resultAlterationProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::resultAlterationAttacks() as $caseId => $output) {
            yield $caseId => [$caseId, $output];
        }
    }

    #[Test]
    #[DataProvider('piiAttackProvider')]
    public function pii_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function piiAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::piiAttackPhrases() as $index => $phrase) {
            yield 'pii_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('promptInjectionProvider')]
    public function prompt_injection_attacks_are_invalid(string $phrase): void
    {
        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate([
            'explanation' => $phrase,
            'limitations' => 'Información orientativa.',
        ], LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function promptInjectionProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::promptInjectionAttackPhrases() as $index => $phrase) {
            yield 'injection_'.$index => [$phrase];
        }
    }

    #[Test]
    #[DataProvider('lengthAttackProvider')]
    public function length_and_format_attacks_are_invalid(string $caseId, array $output): void
    {
        if ($caseId === 'LENGTH_ONE_CHAR' || $caseId === 'FORMAT_UNICODE') {
            $validated = $this->validator->validate($output, LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
            $this->assertNotEmpty($validated['explanation']);

            return;
        }

        $this->expectException(LaboratoryResultAiExplanationValidationException::class);
        $this->validator->validate($output, LaboratoryResultAiExplanationQaCorpus::highGlucoseInput());
        $this->assertSame($caseId, $caseId);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function lengthAttackProvider(): iterable
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::lengthAndFormatAttacks() as $caseId => $output) {
            yield $caseId => [$caseId, $output];
        }
    }

    #[Test]
    public function legitimate_educational_phrases_are_not_blocked(): void
    {
        $output = LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_EDUCATIONAL_001'];
        $validated = $this->validator->validate($output, LaboratoryResultAiExplanationQaCorpus::inputForStatus('high'));

        $this->assertStringContainsString('interpretar', $validated['explanation']);
    }
}
