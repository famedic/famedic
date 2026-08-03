<?php

namespace App\Services\TaxProfiles;

use App\DataTransferObjects\TaxProfiles\ConstanciaExtractionResult;

class ConstanciaExtractionNormalizer
{
    private const REQUIRED_FIELDS = ['name', 'rfc', 'zipcode', 'tax_regime'];

    public function __construct(
        private readonly IndividualTaxpayerValidator $taxpayerValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $localData
     * @param  array<string, mixed>|null  $aiData
     * @param  array<string, mixed>  $validation
     */
    public function normalize(
        array $localData,
        ?array $aiData,
        array $validation,
        string $documentClassification,
        bool $aiAssisted,
        array $extraWarnings = [],
    ): ConstanciaExtractionResult {
        $merged = $this->mergeSources($localData, $aiData);

        $rfc = $this->taxpayerValidator->normalizeRfc($merged['rfc'] ?? null);
        $name = $this->cleanName($merged['name'] ?? $merged['nombre'] ?? $merged['razon_social'] ?? null);
        $razonSocial = $this->cleanName($merged['razon_social'] ?? $name);
        $zipcode = $this->normalizeZipcode($merged['zipcode'] ?? $merged['codigo_postal'] ?? $merged['codigo_postal_original'] ?? null);
        $taxRegime = $this->normalizeTaxRegimeCode($merged['tax_regime'] ?? null)
            ?? $this->mapRegimeFromText($merged['regimen_fiscal'] ?? $merged['regimen_fiscal_original'] ?? null);
        $regimenOriginal = $merged['regimen_fiscal_original']
            ?? $merged['regimen_fiscal']
            ?? null;

        $tipoPersona = $validation['tipo_persona'] ?? 'fisica';
        $taxpayerType = $validation['taxpayer_type'] ?? 'individual';

        $wizardPayload = [
            'rfc' => $rfc,
            'nombre' => $name,
            'razon_social' => $razonSocial,
            'name' => $name,
            'codigo_postal' => $zipcode,
            'zipcode' => $zipcode,
            'codigo_postal_original' => $zipcode,
            'regimen_fiscal' => is_string($regimenOriginal) ? $regimenOriginal : ($taxRegime ? (string) $taxRegime : null),
            'regimen_fiscal_original' => is_string($regimenOriginal) ? $regimenOriginal : null,
            'tax_regime' => $taxRegime,
            'domicilio_fiscal' => $this->nullableString($merged['domicilio_fiscal'] ?? null),
            'fecha_emision' => $this->nullableString($merged['fecha_emision'] ?? $merged['fecha_emision_constancia'] ?? null),
            'fecha_emision_constancia' => $this->nullableString($merged['fecha_emision'] ?? $merged['fecha_emision_constancia'] ?? null),
            'fecha_inscripcion' => $this->nullableString($merged['fecha_inscripcion'] ?? null),
            'estatus_sat' => $this->nullableString($merged['estatus_sat'] ?? null),
            'actividades_economicas' => $this->nullableString($merged['actividades_economicas'] ?? null),
            'tipo_persona' => $tipoPersona,
            'tipo_persona_confianza' => (int) ($merged['tipo_persona_confianza'] ?? ($aiAssisted ? 90 : 70)),
            'tipo_persona_detectado_por' => $aiAssisted
                ? 'openai'
                : ($merged['tipo_persona_detectado_por'] ?? 'sistema'),
        ];

        // Never leak CURP to the browser.
        unset($wizardPayload['curp']);

        $fields = [];
        $extracted = [];
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = match ($field) {
                'name' => $name,
                'rfc' => $rfc,
                'zipcode' => $zipcode,
                'tax_regime' => $taxRegime,
                default => null,
            };

            if ($value !== null && $value !== '') {
                $fields[$field] = ['value' => $value, 'status' => 'extracted'];
                $extracted[] = $field;
            } else {
                $fields[$field] = ['value' => null, 'status' => 'missing'];
                $missing[] = $field;
            }
        }

        $warnings = array_values(array_unique(array_filter(array_merge(
            $validation['warnings'] ?? [],
            $extraWarnings,
            ['Revisa que los datos coincidan con tu constancia.'],
        ))));

        $status = match ($validation['decision'] ?? 'incomplete') {
            'reject_legal_entity' => 'rejected_legal_entity',
            'inconsistent' => 'failed',
            default => $missing === [] ? 'completed' : 'partial',
        };

        if (($validation['decision'] ?? null) === 'inconsistent') {
            $status = 'failed';
        }

        if ($documentClassification === 'unreadable') {
            $status = 'invalid_document';
        }

        if (in_array($documentClassification, ['not_csf'], true) && $status !== 'rejected_legal_entity') {
            $status = 'invalid_document';
        }

        return new ConstanciaExtractionResult(
            status: $status,
            documentClassification: $documentClassification,
            taxpayerType: $taxpayerType,
            tipoPersona: $tipoPersona,
            wizardPayload: $wizardPayload,
            fields: $fields,
            extractedFields: $extracted,
            missingFields: $missing,
            warnings: $warnings,
            rejectionReason: $status === 'rejected_legal_entity'
                ? 'legal_entity_not_allowed'
                : null,
            aiAssisted: $aiAssisted,
        );
    }

    /**
     * @param  array<string, mixed>  $localData
     * @param  array<string, mixed>|null  $aiData
     * @return array<string, mixed>
     */
    private function mergeSources(array $localData, ?array $aiData): array
    {
        $aiData = $aiData ?? [];

        $preferAi = static function (?string $ai, mixed $local): mixed {
            if (is_string($ai) && trim($ai) !== '') {
                return trim($ai);
            }

            return $local;
        };

        return [
            'rfc' => $preferAi($aiData['rfc'] ?? null, $localData['rfc'] ?? null),
            'name' => $preferAi($aiData['name'] ?? null, $localData['nombre'] ?? $localData['razon_social'] ?? null),
            'nombre' => $preferAi($aiData['name'] ?? null, $localData['nombre'] ?? null),
            'razon_social' => $preferAi($aiData['razon_social'] ?? null, $localData['razon_social'] ?? $localData['nombre'] ?? null),
            'zipcode' => $preferAi($aiData['zipcode'] ?? null, $localData['codigo_postal'] ?? null),
            'codigo_postal' => $preferAi($aiData['zipcode'] ?? $aiData['codigo_postal_original'] ?? null, $localData['codigo_postal'] ?? null),
            'codigo_postal_original' => $preferAi($aiData['codigo_postal_original'] ?? null, $localData['codigo_postal'] ?? null),
            'tax_regime' => $preferAi($aiData['tax_regime'] ?? null, null),
            'regimen_fiscal' => $preferAi($aiData['regimen_fiscal_original'] ?? null, $localData['regimen_fiscal'] ?? null),
            'regimen_fiscal_original' => $preferAi($aiData['regimen_fiscal_original'] ?? null, $localData['regimen_fiscal'] ?? null),
            'domicilio_fiscal' => $preferAi($aiData['domicilio_fiscal'] ?? null, $localData['domicilio_fiscal'] ?? null),
            'fecha_emision' => $preferAi($aiData['fecha_emision_constancia'] ?? null, $localData['fecha_emision'] ?? null),
            'fecha_emision_constancia' => $preferAi($aiData['fecha_emision_constancia'] ?? null, $localData['fecha_emision'] ?? null),
            'fecha_inscripcion' => $preferAi($aiData['fecha_inscripcion'] ?? null, $localData['fecha_inscripcion'] ?? null),
            'estatus_sat' => $preferAi($aiData['estatus_sat'] ?? null, $localData['estatus_sat'] ?? null),
            'actividades_economicas' => $preferAi($aiData['actividades_economicas'] ?? null, $localData['actividades_economicas'] ?? null),
            'tipo_persona' => $preferAi($aiData['tipo_persona'] ?? null, $localData['tipo_persona'] ?? null),
            'taxpayer_type' => $aiData['taxpayer_type'] ?? null,
            'tipo_persona_confianza' => $aiData['tipo_persona_confianza'] ?? $localData['tipo_persona_confianza'] ?? null,
            'curp' => $aiData['curp'] ?? null,
        ];
    }

    private function cleanName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : mb_strtoupper($value, 'UTF-8');
    }

    private function normalizeZipcode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        if (preg_match('/\b(\d{5})\b/', (string) $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeTaxRegimeCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^\d{3}$/', $value)) {
            return array_key_exists($value, config('taxregimes.regimes', [])) ? $value : null;
        }

        if (preg_match('/\b(\d{3})\b/', $value, $matches)) {
            $code = $matches[1];

            return array_key_exists($code, config('taxregimes.regimes', [])) ? $code : null;
        }

        return $this->mapRegimeFromText($value);
    }

    private function mapRegimeFromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $needle = mb_strtoupper($text, 'UTF-8');
        $needle = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú'], ['A', 'E', 'I', 'O', 'U'], $needle);

        foreach (config('taxregimes.regimes', []) as $code => $meta) {
            $name = mb_strtoupper((string) ($meta['name'] ?? ''), 'UTF-8');
            $name = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú'], ['A', 'E', 'I', 'O', 'U'], $name);
            if ($name !== '' && (str_contains($needle, $name) || str_contains($name, $needle))) {
                return (string) $code;
            }
        }

        if (str_contains($needle, 'SUELDOS') && str_contains($needle, 'SALARIOS')) {
            return '605';
        }

        if (str_contains($needle, 'ACTIVIDADES EMPRESARIALES')) {
            return '612';
        }

        if (str_contains($needle, 'SIMPLIFICADO DE CONFIANZA') || str_contains($needle, 'RESICO')) {
            return '626';
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
