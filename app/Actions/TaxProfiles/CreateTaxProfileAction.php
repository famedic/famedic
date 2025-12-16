<?php

namespace App\Actions\TaxProfiles;

use App\Models\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class CreateTaxProfileAction
{
    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        ?string $cfdiUse = null,
        ?UploadedFile $fiscalCertificate = null,
        ?array $extractedData = null
    ): TaxProfile {

        $newFilePath = null;

        try {

            if ($fiscalCertificate) {

                if (config('app.env') === 'staging' || config('app.env') === 'testing') {


                    $newFilePath = 'fiscal-certificates/test/' . Str::uuid() . '.pdf';



                } else {

                    $newFilePath = $fiscalCertificate->store('fiscal-certificates');
                }
            }


            $hashConstancia = null;
            if ($fiscalCertificate) {
                if (config('app.env') === 'staging' || config('app.env') === 'testing') {

                    $hashConstancia = 'test_hash_' . Str::random(40);
                } else if ($newFilePath) {

                    $hashConstancia = hash_file('sha256', $fiscalCertificate->path());
                }
            }


            $taxProfileData = [
                'name' => $name,
                'razon_social' => $extractedData['razon_social'] ?? $name,
                'rfc' => Str::upper($rfc),
                'zipcode' => $zipcode,
                'codigo_postal_original' => $extractedData['codigo_postal'] ?? $zipcode,
                'tax_regime' => $taxRegime,
                'regimen_fiscal_original' => $extractedData['regimen_fiscal'] ?? null,
                'cfdi_use' => $cfdiUse ?? 'G03',
                'fiscal_certificate' => $newFilePath,
                'hash_constancia' => $hashConstancia,


                'tipo_persona' => $extractedData['tipo_persona'] ?? $this->determinarTipoPersona($rfc),
                'fecha_emision_constancia' => $extractedData['fecha_emision'] ?? null,
                'estatus_sat' => $extractedData['estatus_sat'] ?? 'Desconocido',
                'tipo_persona_confianza' => $extractedData['tipo_persona_confianza'] ?? 0,
                'tipo_persona_detectado_por' => $extractedData ? 'sistema' : null,
                'verificado_automaticamente' => !empty($extractedData),
                'fecha_verificacion' => !empty($extractedData) ? now() : null,


                'fecha_inscripcion' => $extractedData['fecha_inscripcion'] ?? null,
                'domicilio_fiscal' => $extractedData['domicilio_fiscal'] ?? null,
                'actividades_economicas' => $extractedData['actividades_economicas'] ?? null,
            ];


            return Auth::user()->customer->taxProfiles()->create($taxProfileData);

        } catch (\Throwable $e) {


            if ($newFilePath && config('app.env') !== 'staging' && config('app.env') !== 'testing') {
                dispatch(function () use ($newFilePath) {
                    if (Storage::exists($newFilePath)) {
                        Storage::delete($newFilePath);
                    }
                })->afterResponse();
            }


            throw $e;
        }
    }

    protected function determinarTipoPersona(string $rfc): string
    {
        $rfc = Str::upper(trim($rfc));




        if (strlen($rfc) === 12) {

            return 'moral';
        } elseif (strlen($rfc) === 13) {

            return 'fisica';
        }


        if (preg_match('/^[A-Z&Ñ]{4}\d{2}/', $rfc)) {
            return 'fisica';
        }

        if (preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc)) {
            return 'moral';
        }


        return 'fisica';
    }
}