<?php

namespace App\Actions\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Models\TaxProfile;
use App\Services\TaxProfiles\IndividualTaxpayerValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UpdateTaxProfileAction
{
    public function __construct(
        private readonly IndividualTaxpayerValidator $taxpayerValidator,
    ) {}

    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        string $cfdiUse,
        TaxProfile $taxProfile,
        ?UploadedFile $fiscalCertificate = null,
        ?array $extractedData = null
    ): TaxProfile {
        if ($taxProfile->isUsed()) {
            throw new InvalidArgumentException(
                'Este perfil ya no se puede modificar porque fue utilizado en una solicitud de factura. Puedes usarlo en nuevas solicitudes o crear otro perfil con datos distintos.'
            );
        }

        $rfc = Str::upper(trim($rfc));
        $tipoPersona = is_string($extractedData['tipo_persona'] ?? null)
            ? $extractedData['tipo_persona']
            : ($taxProfile->tipo_persona);

        try {
            $this->taxpayerValidator->assertIndividualForPersistence($rfc, $tipoPersona);
        } catch (ConstanciaExtractionException $e) {
            throw new InvalidArgumentException($e->publicMessage(), 0, $e);
        }

        if ($rfc !== $taxProfile->rfc) {
            $existingProfile = $taxProfile->customer->taxProfiles()
                ->where('rfc', $rfc)
                ->where('id', '!=', $taxProfile->id)
                ->first();

            if ($existingProfile) {
                throw new \Exception('Ya existe otro perfil fiscal con este RFC.');
            }
        }

        $certificatePath = $taxProfile->fiscal_certificate;
        $hashConstancia = $taxProfile->hash_constancia;

        if ($fiscalCertificate) {
            if ($taxProfile->fiscal_certificate
                && config('app.env') !== 'staging'
                && config('app.env') !== 'testing'
                && Storage::exists($taxProfile->fiscal_certificate)) {
                Storage::delete($taxProfile->fiscal_certificate);
            }

            if (config('app.env') === 'staging' || config('app.env') === 'testing') {
                $certificatePath = 'fiscal-certificates/test/'.Str::uuid().'.pdf';
                $hashConstancia = 'test_hash_'.Str::random(40);
            } else {
                $certificatePath = $fiscalCertificate->store('fiscal-certificates');
                $hashConstancia = hash_file('sha256', $fiscalCertificate->path());
            }
        }

        $updateData = [
            'name' => $name,
            'razon_social' => $extractedData['razon_social'] ?? $taxProfile->razon_social ?? $name,
            'rfc' => $rfc,
            'zipcode' => $zipcode,
            'tax_regime' => $taxRegime,
            'cfdi_use' => $cfdiUse,
            'fiscal_certificate' => $certificatePath,
            'hash_constancia' => $hashConstancia,
            'tipo_persona' => 'fisica',
        ];

        if ($extractedData) {
            $updateData = array_merge($updateData, [
                'codigo_postal_original' => $extractedData['codigo_postal'] ?? $extractedData['codigo_postal_original'] ?? $zipcode,
                'regimen_fiscal_original' => $extractedData['regimen_fiscal'] ?? $extractedData['regimen_fiscal_original'] ?? $taxProfile->regimen_fiscal_original,
                'fecha_emision_constancia' => $extractedData['fecha_emision'] ?? $extractedData['fecha_emision_constancia'] ?? $taxProfile->fecha_emision_constancia,
                'estatus_sat' => $extractedData['estatus_sat'] ?? $taxProfile->estatus_sat ?? 'Desconocido',
                'tipo_persona_confianza' => $extractedData['tipo_persona_confianza'] ?? $taxProfile->tipo_persona_confianza ?? 0,
                'tipo_persona_detectado_por' => $extractedData['tipo_persona_detectado_por'] ?? 'sistema',
                'verificado_automaticamente' => true,
                'fecha_verificacion' => now(),
                'fecha_inscripcion' => $extractedData['fecha_inscripcion'] ?? $taxProfile->fecha_inscripcion,
                'domicilio_fiscal' => $extractedData['domicilio_fiscal'] ?? $taxProfile->domicilio_fiscal,
                'actividades_economicas' => $extractedData['actividades_economicas'] ?? $taxProfile->actividades_economicas,
            ]);
        }

        $taxProfile->update($updateData);

        return $taxProfile->fresh();
    }
}
