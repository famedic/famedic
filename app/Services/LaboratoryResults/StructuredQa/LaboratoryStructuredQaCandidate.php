<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaCandidate
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $purchaseItemAssociation
     * @param  array<string, mixed>  $versionAssociation
     */
    public function __construct(
        public readonly string $physicalPdf,
        public readonly int $laboratoryResultVersionId,
        public readonly string $analyteCode,
        public readonly string $analyteNameRaw,
        public readonly string $analyteDisplayName,
        public readonly float|string $value,
        public readonly string $unitRaw,
        public readonly ?string $catalogDefaultUnit,
        public readonly ?string $referenceText,
        public readonly ?float $referenceLow,
        public readonly ?float $referenceHigh,
        public readonly string $referenceClass,
        public readonly string $valueType,
        public readonly int $sourcePage,
        public readonly float $confidence,
        public readonly ?int $laboratoryPurchaseItemId,
        public readonly ?string $purchaseAssociationMethod,
        public readonly bool $isCbc,
        public readonly array $identity,
        public readonly array $purchaseItemAssociation,
        public readonly array $versionAssociation,
        public readonly string $structuredReadiness,
    ) {}

    public function candidateKey(): string
    {
        return implode('|', [
            $this->physicalPdf,
            $this->analyteCode,
            (string) $this->value,
            $this->unitRaw,
        ]);
    }

    public function observationIdentityHash(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->laboratoryResultVersionId,
            $this->analyteCode,
            (string) $this->value,
            $this->unitRaw,
            (string) $this->sourcePage,
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromManifestRow(array $row): self
    {
        return new self(
            physicalPdf: (string) $row['physical_pdf'],
            laboratoryResultVersionId: (int) $row['laboratory_result_version_id'],
            analyteCode: (string) $row['analyte_code'],
            analyteNameRaw: (string) ($row['analyte_name_raw'] ?? $row['analyte_code']),
            analyteDisplayName: (string) ($row['analyte'] ?? $row['analyte_name_raw'] ?? $row['analyte_code']),
            value: $row['value'],
            unitRaw: (string) $row['unit_raw'],
            catalogDefaultUnit: isset($row['catalog_default_unit']) ? (string) $row['catalog_default_unit'] : null,
            referenceText: isset($row['reference_text']) ? (string) $row['reference_text'] : null,
            referenceLow: isset($row['reference_low']) ? (float) $row['reference_low'] : null,
            referenceHigh: isset($row['reference_high']) ? (float) $row['reference_high'] : null,
            referenceClass: (string) ($row['reference_class'] ?? 'unknown'),
            valueType: (string) ($row['value_type'] ?? 'numeric'),
            sourcePage: (int) ($row['source_page'] ?? 0),
            confidence: (float) ($row['confidence'] ?? 0),
            laboratoryPurchaseItemId: isset($row['purchase_item_id'])
                ? (int) $row['purchase_item_id']
                : (isset($row['purchase_item_association']['laboratory_purchase_item_id'])
                    ? (int) $row['purchase_item_association']['laboratory_purchase_item_id']
                    : null),
            purchaseAssociationMethod: isset($row['purchase_association_method'])
                ? (string) $row['purchase_association_method']
                : null,
            isCbc: (bool) ($row['is_cbc'] ?? str_starts_with((string) $row['analyte_code'], 'FAMEDIC_CBC_')),
            identity: (array) ($row['identity'] ?? []),
            purchaseItemAssociation: (array) ($row['purchase_item_association'] ?? []),
            versionAssociation: (array) ($row['version_association'] ?? []),
            structuredReadiness: (string) ($row['structured_readiness'] ?? ''),
        );
    }
}
