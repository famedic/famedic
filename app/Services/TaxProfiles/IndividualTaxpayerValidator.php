<?php

namespace App\Services\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use InvalidArgumentException;

class IndividualTaxpayerValidator
{
    /** Regímenes habitualmente asociados a personas morales (solo consistencia). */
    public const MORAL_REGIME_CODES = ['601', '603', '620', '623', '624'];

    /**
     * @param  array{
     *     rfc?: ?string,
     *     tipo_persona?: ?string,
     *     taxpayer_type?: ?string,
     *     tax_regime?: ?string,
     *     regimen_fiscal?: ?string,
     *     curp?: ?string,
     * }  $normalized
     * @param  array{
     *     explicit_persona_moral?: bool,
     *     explicit_persona_fisica?: bool,
     *     document_classification?: ?string,
     *     text_mentions_curp?: bool,
     * }  $documentSignals
     * @return array{
     *     decision: 'accept'|'reject_legal_entity'|'inconsistent'|'incomplete',
     *     tipo_persona: 'fisica'|null,
     *     taxpayer_type: 'individual'|'legal_entity'|'unknown',
     *     warnings: list<string>,
     *     reasons: list<string>,
     * }
     */
    public function validate(array $normalized, array $documentSignals = []): array
    {
        $rfc = $this->normalizeRfc($normalized['rfc'] ?? null);
        $tipoPersona = $this->normalizeTipoPersona($normalized['tipo_persona'] ?? null);
        $taxpayerType = $this->normalizeTaxpayerType($normalized['taxpayer_type'] ?? null);
        $taxRegime = $this->normalizeRegimeCode(
            $normalized['tax_regime'] ?? $normalized['regimen_fiscal'] ?? null
        );
        $curp = $this->normalizeCurp($normalized['curp'] ?? null);

        $warnings = [];
        $reasons = [];

        $explicitMoral = (bool) ($documentSignals['explicit_persona_moral'] ?? false);
        $classification = $documentSignals['document_classification'] ?? null;

        if ($explicitMoral || $classification === 'csf_legal_entity') {
            return $this->rejectLegalEntity(['explicit_persona_moral']);
        }

        if ($tipoPersona === 'moral') {
            return $this->rejectLegalEntity(['tipo_persona_moral']);
        }

        if ($taxpayerType === 'legal_entity' && ($rfc === null || strlen($rfc) === 12 || $explicitMoral)) {
            return $this->rejectLegalEntity(['structured_legal_entity']);
        }

        if ($rfc !== null && strlen($rfc) === 12 && $this->looksLikeMoralRfc($rfc)) {
            return $this->rejectLegalEntity(['rfc_length_12']);
        }

        if ($rfc !== null && strlen($rfc) === 12) {
            return $this->rejectLegalEntity(['rfc_length_12']);
        }

        if ($taxpayerType === 'legal_entity') {
            // Sin evidencia consistente de moral (RFC 13 + sin etiqueta): inconsistente, no completed.
            return [
                'decision' => 'inconsistent',
                'tipo_persona' => null,
                'taxpayer_type' => 'unknown',
                'warnings' => ['Los datos del documento no son consistentes. Verifica RFC y tipo de persona.'],
                'reasons' => ['taxpayer_type_legal_entity_without_hard_signal'],
            ];
        }

        $isValidIndividualRfc = $rfc !== null && $this->isValidIndividualRfc($rfc);

        if ($rfc !== null && ! $isValidIndividualRfc && strlen($rfc) === 13) {
            return [
                'decision' => 'inconsistent',
                'tipo_persona' => null,
                'taxpayer_type' => 'unknown',
                'warnings' => ['El RFC no tiene un formato válido de persona física.'],
                'reasons' => ['invalid_individual_rfc_format'],
            ];
        }

        if ($taxRegime !== null && in_array($taxRegime, self::MORAL_REGIME_CODES, true)) {
            if ($isValidIndividualRfc) {
                $warnings[] = 'El régimen fiscal suele asociarse a personas morales; confírmalo antes de guardar.';
            } elseif ($rfc === null) {
                $warnings[] = 'El régimen fiscal sugiere persona moral; se requiere un RFC de persona física.';
            } else {
                return $this->rejectLegalEntity(['moral_regime_with_non_individual_rfc']);
            }
        }

        if ($tipoPersona === 'desconocido') {
            return [
                'decision' => 'incomplete',
                'tipo_persona' => null,
                'taxpayer_type' => $isValidIndividualRfc ? 'individual' : 'unknown',
                'warnings' => ['No se pudo determinar el tipo de persona con certeza.'],
                'reasons' => ['tipo_persona_desconocido'],
            ];
        }

        if ($curp !== null && ! $this->isValidCurp($curp)) {
            $warnings[] = 'La CURP detectada no tiene un formato válido; se ignoró como señal.';
        }

        if ($isValidIndividualRfc) {
            if ($tipoPersona !== null && $tipoPersona !== 'fisica' && $tipoPersona !== 'desconocido') {
                return [
                    'decision' => 'inconsistent',
                    'tipo_persona' => null,
                    'taxpayer_type' => 'unknown',
                    'warnings' => ['RFC y tipo de persona son contradictorios.'],
                    'reasons' => ['rfc_tipo_contradiction'],
                ];
            }

            return [
                'decision' => 'accept',
                'tipo_persona' => 'fisica',
                'taxpayer_type' => 'individual',
                'warnings' => $warnings,
                'reasons' => $reasons,
            ];
        }

        if ($rfc === null) {
            return [
                'decision' => 'incomplete',
                'tipo_persona' => null,
                'taxpayer_type' => 'unknown',
                'warnings' => $warnings,
                'reasons' => ['rfc_missing'],
            ];
        }

        return [
            'decision' => 'inconsistent',
            'tipo_persona' => null,
            'taxpayer_type' => 'unknown',
            'warnings' => array_values(array_unique(array_merge($warnings, [
                'No se pudo validar el RFC como persona física.',
            ]))),
            'reasons' => ['rfc_not_individual'],
        ];
    }

    /**
     * Defensa para store / update / invoice: solo personas físicas.
     *
     * @throws InvalidArgumentException|ConstanciaExtractionException
     */
    public function assertIndividualForPersistence(string $rfc, ?string $tipoPersona = null): void
    {
        $rfc = $this->normalizeRfc($rfc) ?? '';
        $tipoPersona = $this->normalizeTipoPersona($tipoPersona);

        if ($tipoPersona === 'moral') {
            throw ConstanciaExtractionException::legalEntityNotAllowed();
        }

        if ($tipoPersona === 'desconocido') {
            throw new InvalidArgumentException(
                'No se puede guardar el perfil hasta confirmar que corresponde a una persona física.'
            );
        }

        if (strlen($rfc) === 12) {
            throw ConstanciaExtractionException::legalEntityNotAllowed();
        }

        if (! $this->isValidIndividualRfc($rfc)) {
            throw new InvalidArgumentException(
                'El RFC debe tener 13 caracteres con formato válido de persona física (XXXX999999XXX).'
            );
        }
    }

    public function isValidIndividualRfc(string $rfc): bool
    {
        $rfc = strtoupper(trim($rfc));

        return (bool) preg_match('/^[A-ZÑ&]{4}[0-9]{6}[A-Z0-9]{3}$/', $rfc);
    }

    public function looksLikeMoralRfc(string $rfc): bool
    {
        $rfc = strtoupper(trim($rfc));

        return (bool) preg_match('/^[A-ZÑ&]{3}[0-9]{6}[A-Z0-9]{3}$/', $rfc);
    }

    public function normalizeRfc(?string $rfc): ?string
    {
        if ($rfc === null) {
            return null;
        }

        $rfc = strtoupper(preg_replace('/\s+/', '', trim($rfc)) ?? '');

        return $rfc === '' ? null : $rfc;
    }

    public function detectExplicitPersonaMoral(string $text): bool
    {
        $normalized = mb_strtoupper($text, 'UTF-8');
        $normalized = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú'], ['A', 'E', 'I', 'O', 'U'], $normalized);

        return (bool) preg_match('/PERSONA\s+MORAL/', $normalized);
    }

    public function detectExplicitPersonaFisica(string $text): bool
    {
        $normalized = mb_strtoupper($text, 'UTF-8');
        $normalized = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú'], ['A', 'E', 'I', 'O', 'U'], $normalized);

        return (bool) preg_match('/PERSONA\s+FISICA/', $normalized);
    }

    public function extractCurpFromText(string $text): ?string
    {
        if (preg_match('/\b([A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[0-9A-Z]\d)\b/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    public function isValidCurp(string $curp): bool
    {
        return (bool) preg_match('/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[0-9A-Z]\d$/i', $curp);
    }

    public function looksLikeConstancia(string $text): bool
    {
        $normalized = mb_strtoupper($text, 'UTF-8');
        $normalized = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú'], ['A', 'E', 'I', 'O', 'U'], $normalized);

        return str_contains($normalized, 'CONSTANCIA DE SITUACION FISCAL')
            || str_contains($normalized, 'CEDULA DE IDENTIFICACION FISCAL')
            || str_contains($normalized, 'REGISTRO FEDERAL DE CONTRIBUYENTES')
            || (str_contains($normalized, 'RFC') && str_contains($normalized, 'REGIMEN'));
    }

    /**
     * @return array{
     *     decision: 'reject_legal_entity',
     *     tipo_persona: null,
     *     taxpayer_type: 'legal_entity',
     *     warnings: list<string>,
     *     reasons: list<string>,
     * }
     */
    private function rejectLegalEntity(array $reasons): array
    {
        return [
            'decision' => 'reject_legal_entity',
            'tipo_persona' => null,
            'taxpayer_type' => 'legal_entity',
            'warnings' => [],
            'reasons' => $reasons,
        ];
    }

    private function normalizeTipoPersona(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'fisica', 'física', 'individual' => 'fisica',
            'moral', 'legal_entity' => 'moral',
            'desconocido', 'unknown' => 'desconocido',
            default => $value,
        };
    }

    private function normalizeTaxpayerType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'individual', 'fisica', 'física' => 'individual',
            'legal_entity', 'moral' => 'legal_entity',
            'unknown', 'desconocido' => 'unknown',
            default => 'unknown',
        };
    }

    private function normalizeRegimeCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (preg_match('/\b(\d{3})\b/', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeCurp(?string $curp): ?string
    {
        if ($curp === null || trim($curp) === '') {
            return null;
        }

        return strtoupper(trim($curp));
    }
}
