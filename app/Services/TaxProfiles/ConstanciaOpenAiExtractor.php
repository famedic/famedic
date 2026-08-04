<?php

namespace App\Services\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use RuntimeException;

class ConstanciaOpenAiExtractor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un extractor fiscal para constancias de situación fiscal (CSF) del SAT en México.
Debes clasificar el documento y extraer solo datos presentes en el texto.
No inventes valores. Si un campo no aparece, usa null.
Famedic solo acepta personas físicas.
Si el documento es de persona moral, clasifícalo como csf_legal_entity y taxpayer_type=legal_entity.
Responde únicamente con el JSON del schema.
PROMPT;

    public function __construct(
        private readonly OpenAiClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $documentText): array
    {
        $maxChars = (int) config('services.openai.max_context_chars', 12000);
        $text = Str::limit($documentText, max($maxChars - 500, 1000), '');

        try {
            return $this->client->chatCompletion(
                messages: [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => "Texto extraído de la constancia fiscal:\n\n".$text],
                ],
                model: config('services.openai.tax_profile_model') ?: null,
                jsonSchema: $this->schema(),
                schemaName: 'constancia_fiscal_extraction',
                temperature: 0,
            );
        } catch (ConstanciaExtractionException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'timeout')) {
                throw ConstanciaExtractionException::extractionTimeout();
            }

            throw new RuntimeException('structured_extraction_failed', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['completed', 'partial', 'rejected_legal_entity', 'invalid_document', 'failed'],
                ],
                'document_classification' => [
                    'type' => 'string',
                    'enum' => ['csf_individual', 'csf_legal_entity', 'not_csf', 'unreadable', 'unknown'],
                ],
                'taxpayer_type' => [
                    'type' => 'string',
                    'enum' => ['individual', 'legal_entity', 'unknown'],
                ],
                'tipo_persona' => [
                    'type' => ['string', 'null'],
                    'enum' => ['fisica', 'moral', null],
                ],
                'name' => ['type' => ['string', 'null']],
                'razon_social' => ['type' => ['string', 'null']],
                'rfc' => ['type' => ['string', 'null']],
                'curp' => ['type' => ['string', 'null']],
                'zipcode' => ['type' => ['string', 'null']],
                'codigo_postal_original' => ['type' => ['string', 'null']],
                'tax_regime' => ['type' => ['string', 'null']],
                'regimen_fiscal_original' => ['type' => ['string', 'null']],
                'domicilio_fiscal' => ['type' => ['string', 'null']],
                'fecha_emision_constancia' => ['type' => ['string', 'null']],
                'fecha_inscripcion' => ['type' => ['string', 'null']],
                'estatus_sat' => ['type' => ['string', 'null']],
                'actividades_economicas' => ['type' => ['string', 'null']],
                'tipo_persona_confianza' => ['type' => ['integer', 'null']],
                'tipo_persona_detectado_por' => ['type' => ['string', 'null']],
                'extracted_fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'missing_fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'rejection_reason' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'status',
                'document_classification',
                'taxpayer_type',
                'tipo_persona',
                'name',
                'razon_social',
                'rfc',
                'curp',
                'zipcode',
                'codigo_postal_original',
                'tax_regime',
                'regimen_fiscal_original',
                'domicilio_fiscal',
                'fecha_emision_constancia',
                'fecha_inscripcion',
                'estatus_sat',
                'actividades_economicas',
                'tipo_persona_confianza',
                'tipo_persona_detectado_por',
                'extracted_fields',
                'missing_fields',
                'warnings',
                'rejection_reason',
            ],
        ];
    }
}
