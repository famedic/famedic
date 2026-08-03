<?php

namespace App\DataTransferObjects\TaxProfiles;

class ConstanciaExtractionResult
{
    /**
     * @param  list<string>  $extractedFields
     * @param  list<string>  $missingFields
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $wizardPayload  Flat keys expected by TaxProfileForm
     * @param  array<string, array{value: mixed, status: string}>  $fields
     */
    public function __construct(
        public readonly string $status,
        public readonly string $documentClassification,
        public readonly string $taxpayerType,
        public readonly ?string $tipoPersona,
        public readonly array $wizardPayload,
        public readonly array $fields,
        public readonly array $extractedFields,
        public readonly array $missingFields,
        public readonly array $warnings,
        public readonly ?string $rejectionReason = null,
        public readonly ?string $errorCode = null,
        public readonly bool $aiAssisted = false,
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->status, ['completed', 'partial'], true);
    }

    /**
     * HTTP JSON compatible with the current wizard (flat fields) plus PF-IA metadata.
     *
     * @return array<string, mixed>
     */
    public function toHttpData(): array
    {
        return array_merge($this->wizardPayload, [
            'status' => $this->status,
            'document_classification' => $this->documentClassification,
            'taxpayer_type' => $this->taxpayerType,
            'tipo_persona' => $this->tipoPersona,
            'missing_fields' => $this->missingFields,
            'extracted_fields' => $this->extractedFields,
            'warnings' => $this->warnings,
            'fields' => $this->fields,
            'ai_assisted' => $this->aiAssisted,
            'rejection_reason' => $this->rejectionReason,
        ]);
    }
}
