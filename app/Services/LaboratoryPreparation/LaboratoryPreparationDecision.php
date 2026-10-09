<?php

namespace App\Services\LaboratoryPreparation;

use InvalidArgumentException;

class LaboratoryPreparationDecision
{
    public const STATUS_AUTO_CONSOLIDATED = 'AUTO_CONSOLIDATED';

    public const STATUS_FALLBACK_ORIGINAL = 'FALLBACK_ORIGINAL';

    public const FALLBACK_CLINICAL_CONFLICT = 'clinical_conflict';

    public const FALLBACK_FUNCTIONAL_AMBIGUITY = 'functional_ambiguity';

    public const FALLBACK_SOURCE_QUALITY = 'source_quality';

    public const FALLBACK_TECHNICAL_AI_FAILURE = 'technical_ai_failure';

    /**
     * @param  list<string>  $rulesApplied
     * @param  array<string, mixed>|null  $consolidatedRequirements
     * @param  list<array<string, mixed>>  $originalInstructions
     */
    private function __construct(
        public readonly string $status,
        public readonly string $orderId,
        public readonly ?string $rulesVersion,
        public readonly array $rulesApplied,
        public readonly ?string $fallbackReason,
        public readonly ?string $fallbackCategory,
        public readonly bool $needsProviderReview,
        public readonly ?array $consolidatedRequirements,
        public readonly array $originalInstructions,
    ) {
        $this->assertValid();
    }

    /**
     * @param  list<string>  $rulesApplied
     * @param  array<string, mixed>  $consolidatedRequirements
     * @param  list<array<string, mixed>>  $originalInstructions
     */
    public static function autoConsolidated(
        string $orderId,
        string $rulesVersion,
        array $rulesApplied,
        array $consolidatedRequirements,
        array $originalInstructions,
    ): self {
        return new self(
            status: self::STATUS_AUTO_CONSOLIDATED,
            orderId: $orderId,
            rulesVersion: $rulesVersion,
            rulesApplied: $rulesApplied,
            fallbackReason: null,
            fallbackCategory: null,
            needsProviderReview: false,
            consolidatedRequirements: $consolidatedRequirements,
            originalInstructions: $originalInstructions,
        );
    }

    /**
     * @param  list<string>  $rulesApplied
     * @param  list<array<string, mixed>>  $originalInstructions
     */
    public static function fallbackOriginal(
        string $orderId,
        ?string $rulesVersion,
        array $rulesApplied,
        string $fallbackReason,
        string $fallbackCategory,
        bool $needsProviderReview,
        array $originalInstructions,
    ): self {
        return new self(
            status: self::STATUS_FALLBACK_ORIGINAL,
            orderId: $orderId,
            rulesVersion: $rulesVersion,
            rulesApplied: $rulesApplied,
            fallbackReason: $fallbackReason,
            fallbackCategory: $fallbackCategory,
            needsProviderReview: $needsProviderReview,
            consolidatedRequirements: null,
            originalInstructions: $originalInstructions,
        );
    }

    /**
     * @return array{
     *     status: string,
     *     order_id: string,
     *     rules_version: string|null,
     *     rules_applied: list<string>,
     *     fallback_reason: string|null,
     *     fallback_category: string|null,
     *     needs_provider_review: bool,
     *     consolidated_requirements: array<string, mixed>|null,
     *     original_instructions: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'order_id' => $this->orderId,
            'rules_version' => $this->rulesVersion,
            'rules_applied' => $this->rulesApplied,
            'fallback_reason' => $this->fallbackReason,
            'fallback_category' => $this->fallbackCategory,
            'needs_provider_review' => $this->needsProviderReview,
            'consolidated_requirements' => $this->consolidatedRequirements,
            'original_instructions' => $this->originalInstructions,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     order_id: string,
     *     consolidated_requirements: array<string, mixed>|null,
     *     operational_requirements: array<string, mixed>,
     *     original_instructions: list<array{study_id?: string|null, study_name: string, source_instructions: string}>,
     *     fallback_reason: string|null,
     *     needs_provider_review: bool,
     *     trace: array{rules_version: string|null, rules_applied: list<string>, fallback_category: string|null}
     * }
     */
    public function toSchemaPayload(): array
    {
        return [
            'status' => $this->status,
            'order_id' => $this->orderId,
            'consolidated_requirements' => $this->consolidatedRequirements,
            'operational_requirements' => [],
            'original_instructions' => $this->originalInstructions,
            'fallback_reason' => $this->fallbackReason,
            'needs_provider_review' => $this->needsProviderReview,
            'trace' => [
                'rules_version' => $this->rulesVersion,
                'rules_applied' => $this->rulesApplied,
                'fallback_category' => $this->fallbackCategory,
            ],
        ];
    }

    private function assertValid(): void
    {
        if (trim($this->orderId) === '') {
            throw new InvalidArgumentException('Laboratory preparation decisions require an order id.');
        }

        $this->assertOriginalInstructionsAreValid();

        if (! in_array($this->status, [
            self::STATUS_AUTO_CONSOLIDATED,
            self::STATUS_FALLBACK_ORIGINAL,
        ], true)) {
            throw new InvalidArgumentException('Invalid laboratory preparation decision status.');
        }

        if ($this->status === self::STATUS_AUTO_CONSOLIDATED) {
            if ($this->rulesVersion === null || trim($this->rulesVersion) === '') {
                throw new InvalidArgumentException('Auto-consolidated decisions require a rules version.');
            }

            if ($this->rulesApplied === []) {
                throw new InvalidArgumentException('Auto-consolidated decisions require applied rules.');
            }

            if ($this->consolidatedRequirements === null || $this->consolidatedRequirements === []) {
                throw new InvalidArgumentException('Auto-consolidated decisions require consolidated requirements.');
            }

            if ($this->fallbackReason !== null || $this->fallbackCategory !== null || $this->needsProviderReview) {
                throw new InvalidArgumentException('Auto-consolidated decisions cannot carry fallback metadata.');
            }
        }

        if ($this->status === self::STATUS_FALLBACK_ORIGINAL) {
            if ($this->fallbackReason === null || trim($this->fallbackReason) === '') {
                throw new InvalidArgumentException('Fallback decisions require an internal reason.');
            }

            if (! in_array($this->fallbackCategory, [
                self::FALLBACK_CLINICAL_CONFLICT,
                self::FALLBACK_FUNCTIONAL_AMBIGUITY,
                self::FALLBACK_SOURCE_QUALITY,
                self::FALLBACK_TECHNICAL_AI_FAILURE,
            ], true)) {
                throw new InvalidArgumentException('Fallback decisions require a valid category.');
            }

            if ($this->consolidatedRequirements !== null) {
                throw new InvalidArgumentException('Fallback decisions must not include consolidated requirements.');
            }

            if ($this->fallbackCategory === self::FALLBACK_TECHNICAL_AI_FAILURE && $this->needsProviderReview) {
                throw new InvalidArgumentException('Technical AI fallbacks must not require provider review by themselves.');
            }
        }
    }

    private function assertOriginalInstructionsAreValid(): void
    {
        foreach ($this->originalInstructions as $instruction) {
            if (! is_array($instruction)) {
                throw new InvalidArgumentException('Original instructions must be objects.');
            }

            if (! array_key_exists('study_name', $instruction) || trim((string) $instruction['study_name']) === '') {
                throw new InvalidArgumentException('Original instructions require a study name.');
            }

            if (! array_key_exists('source_instructions', $instruction) || ! is_string($instruction['source_instructions'])) {
                throw new InvalidArgumentException('Original instructions require source instructions.');
            }

            if (
                array_key_exists('study_id', $instruction)
                && $instruction['study_id'] !== null
                && ! is_string($instruction['study_id'])
            ) {
                throw new InvalidArgumentException('Original instruction study ids must be strings or null.');
            }
        }
    }
}
