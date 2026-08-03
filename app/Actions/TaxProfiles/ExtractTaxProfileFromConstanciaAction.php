<?php

namespace App\Actions\TaxProfiles;

use App\DataTransferObjects\TaxProfiles\ConstanciaExtractionResult;
use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Services\ConstanciaFiscalService;
use App\Services\TaxProfiles\ConstanciaExtractionNormalizer;
use App\Services\TaxProfiles\ConstanciaOpenAiExtractor;
use App\Services\TaxProfiles\IndividualTaxpayerValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractTaxProfileFromConstanciaAction
{
    public function __construct(
        private readonly ConstanciaFiscalService $constanciaFiscalService,
        private readonly ConstanciaOpenAiExtractor $openAiExtractor,
        private readonly ConstanciaExtractionNormalizer $normalizer,
        private readonly IndividualTaxpayerValidator $taxpayerValidator,
    ) {}

    public function __invoke(UploadedFile $file): ConstanciaExtractionResult
    {
        $startedAt = microtime(true);
        $userId = auth()->id();
        $customerId = auth()->user()?->customer?->id;
        $tempPath = null;
        $lock = null;

        try {
            $contents = $file->get();
            if ($contents === false || $contents === '') {
                throw ConstanciaExtractionException::invalidDocument();
            }

            $fingerprint = hash('sha256', $contents);
            $lock = Cache::lock('tax-profile-extract:'.$userId.':'.$fingerprint, 60);
            if (! $lock->get()) {
                throw ConstanciaExtractionException::alreadyProcessing();
            }

            $tempPath = $this->stageTemporaryCopy($contents);

            $workingFile = $tempPath
                ? new UploadedFile($tempPath, $file->getClientOriginalName(), 'application/pdf', null, true)
                : $file;

            $text = $this->constanciaFiscalService->extractText($workingFile);
            $localData = $this->constanciaFiscalService->extractDeterministicData($text);

            $explicitMoral = $this->taxpayerValidator->detectExplicitPersonaMoral($text);
            $curpFromText = $this->taxpayerValidator->extractCurpFromText($text);

            if ($explicitMoral) {
                throw ConstanciaExtractionException::legalEntityNotAllowed();
            }

            $localRfc = $this->taxpayerValidator->normalizeRfc($localData['rfc'] ?? null);
            if ($localRfc && strlen($localRfc) === 12) {
                throw ConstanciaExtractionException::legalEntityNotAllowed();
            }

            $looksLikeCsf = $this->taxpayerValidator->looksLikeConstancia($text);
            $documentClassification = $looksLikeCsf ? 'unknown' : 'not_csf';

            if (! $looksLikeCsf && empty($localData['rfc'])) {
                throw ConstanciaExtractionException::notCsf();
            }

            $aiData = null;
            $aiAssisted = false;
            $aiWarnings = [];
            $aiFailed = false;

            try {
                $aiData = $this->openAiExtractor->extract($text);
                $aiAssisted = true;

                if (($aiData['document_classification'] ?? null) === 'csf_legal_entity'
                    || ($aiData['taxpayer_type'] ?? null) === 'legal_entity'
                    || ($aiData['tipo_persona'] ?? null) === 'moral'
                    || ($aiData['status'] ?? null) === 'rejected_legal_entity') {
                    // Still run deterministic validator; hard reject below if confirmed.
                }

                if (($aiData['document_classification'] ?? null) === 'not_csf' && empty($localData['rfc'])) {
                    throw ConstanciaExtractionException::notCsf();
                }

                if (($aiData['document_classification'] ?? null) === 'unreadable' && empty($localData['rfc'])) {
                    throw ConstanciaExtractionException::unreadable();
                }

                $documentClassification = (string) ($aiData['document_classification'] ?? $documentClassification);
            } catch (ConstanciaExtractionException $e) {
                throw $e;
            } catch (Throwable $e) {
                $aiFailed = true;
                $aiAssisted = false;
                $aiData = null;
                Log::warning('Extracción IA de constancia no disponible; evaluando fallback local', [
                    'operation' => 'constancia_extract_ai',
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                    'result' => 'ai_fallback',
                    'exception_class' => $e::class,
                ]);
            }

            $signals = [
                'explicit_persona_moral' => $explicitMoral
                    || (($aiData['document_classification'] ?? null) === 'csf_legal_entity'),
                'explicit_persona_fisica' => $this->taxpayerValidator->detectExplicitPersonaFisica($text),
                'document_classification' => $documentClassification,
                'text_mentions_curp' => $curpFromText !== null,
            ];

            $candidate = [
                'rfc' => $aiData['rfc'] ?? $localData['rfc'] ?? null,
                'tipo_persona' => $aiData['tipo_persona'] ?? $localData['tipo_persona'] ?? null,
                'taxpayer_type' => $aiData['taxpayer_type'] ?? null,
                'tax_regime' => $aiData['tax_regime'] ?? null,
                'regimen_fiscal' => $aiData['regimen_fiscal_original'] ?? $localData['regimen_fiscal'] ?? null,
                'curp' => $curpFromText ?? ($aiData['curp'] ?? null),
            ];

            $validation = $this->taxpayerValidator->validate($candidate, $signals);

            if ($validation['decision'] === 'reject_legal_entity') {
                throw ConstanciaExtractionException::legalEntityNotAllowed();
            }

            if ($validation['decision'] === 'inconsistent') {
                throw ConstanciaExtractionException::inconsistentData();
            }

            if ($aiFailed) {
                $localValidation = $this->taxpayerValidator->validate([
                    'rfc' => $localData['rfc'] ?? null,
                    'tipo_persona' => $localData['tipo_persona'] ?? null,
                    'taxpayer_type' => null,
                    'regimen_fiscal' => $localData['regimen_fiscal'] ?? null,
                    'curp' => $curpFromText,
                ], [
                    'explicit_persona_moral' => $explicitMoral,
                    'document_classification' => $looksLikeCsf ? 'csf_individual' : $documentClassification,
                ]);

                if ($localValidation['decision'] === 'reject_legal_entity') {
                    throw ConstanciaExtractionException::legalEntityNotAllowed();
                }

                if (! $this->localExtractionIsSufficient($localData, $localValidation)) {
                    throw ConstanciaExtractionException::extractionFailed();
                }

                $validation = $localValidation;
                $documentClassification = $looksLikeCsf ? 'csf_individual' : $documentClassification;
                $aiWarnings[] = 'Algunos datos se obtuvieron solo con lectura local del PDF. Revisa con cuidado antes de guardar.';
            }

            if ($documentClassification === 'unknown' && $looksLikeCsf && ($validation['decision'] ?? null) === 'accept') {
                $documentClassification = 'csf_individual';
            }

            $result = $this->normalizer->normalize(
                localData: $localData,
                aiData: $aiData,
                validation: $validation,
                documentClassification: $documentClassification,
                aiAssisted: $aiAssisted,
                extraWarnings: $aiWarnings,
            );

            if (! $result->isSuccess()) {
                if ($result->status === 'rejected_legal_entity') {
                    throw ConstanciaExtractionException::legalEntityNotAllowed();
                }

                throw ConstanciaExtractionException::extractionFailed();
            }

            // Strip any residual CURP before returning.
            $payload = $result->toHttpData();
            unset($payload['curp']);

            Log::info('Extracción de constancia respondida', [
                'operation' => 'constancia_extract_pipeline',
                'user_id' => $userId,
                'customer_id' => $customerId,
                'result' => $result->status,
                'ai_assisted' => $aiAssisted,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return new ConstanciaExtractionResult(
                status: $result->status,
                documentClassification: $result->documentClassification,
                taxpayerType: $result->taxpayerType,
                tipoPersona: $result->tipoPersona,
                wizardPayload: array_diff_key($result->wizardPayload, ['curp' => true]),
                fields: $result->fields,
                extractedFields: $result->extractedFields,
                missingFields: $result->missingFields,
                warnings: $result->warnings,
                rejectionReason: $result->rejectionReason,
                aiAssisted: $result->aiAssisted,
            );
        } catch (ConstanciaExtractionException $e) {
            Log::warning('Extracción de constancia fallida', [
                'operation' => 'constancia_extract_pipeline',
                'user_id' => $userId,
                'customer_id' => $customerId,
                'result' => $e->errorCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        } catch (Throwable $e) {
            Log::error('Error inesperado en pipeline de extracción', [
                'operation' => 'constancia_extract_pipeline',
                'user_id' => $userId,
                'customer_id' => $customerId,
                'result' => 'exception',
                'exception_class' => $e::class,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw ConstanciaExtractionException::extractionFailed();
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }

            if ($lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // ignore
                }
            }
        }
    }

    private function stageTemporaryCopy(string $contents): ?string
    {
        $dir = storage_path('app/tmp/tax-profile-extract');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $dir.'/'.uniqid('csf_', true).'.pdf';
        if (@file_put_contents($path, $contents) === false) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $localData
     * @param  array<string, mixed>  $validation
     */
    private function localExtractionIsSufficient(array $localData, array $validation): bool
    {
        if (($validation['decision'] ?? null) !== 'accept') {
            return false;
        }

        $rfc = $this->taxpayerValidator->normalizeRfc($localData['rfc'] ?? null);
        $name = $localData['nombre'] ?? $localData['razon_social'] ?? null;
        $zip = $localData['codigo_postal'] ?? null;
        $regime = $localData['regimen_fiscal'] ?? null;

        return $rfc
            && $this->taxpayerValidator->isValidIndividualRfc($rfc)
            && is_string($name) && trim($name) !== ''
            && is_string($zip) && preg_match('/^\d{5}$/', $zip)
            && is_string($regime) && trim($regime) !== '';
    }
}
