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
        // Variable para almacenar la ruta del archivo en caso de que falle el guardado
        $newFilePath = null;

        try {
            // Procesar el archivo de constancia fiscal
            if ($fiscalCertificate) {
                // Guardar el archivo de la misma forma que en tu ejemplo
                $newFilePath = $fiscalCertificate->store('fiscal-certificates');
            }

            // Calcular hash del archivo si existe
            $hashConstancia = null;
            if ($fiscalCertificate && $newFilePath) {
                $hashConstancia = hash_file('sha256', $fiscalCertificate->path());
            }

            // Preparar datos para crear el perfil
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
                
                // Campos extraídos automáticamente
                'tipo_persona' => $extractedData['tipo_persona'] ?? $this->determinarTipoPersona($rfc),
                'fecha_emision_constancia' => $extractedData['fecha_emision'] ?? null,
                'estatus_sat' => $extractedData['estatus_sat'] ?? 'Desconocido',
                'tipo_persona_confianza' => $extractedData['tipo_persona_confianza'] ?? 0,
                'tipo_persona_detectado_por' => $extractedData ? 'sistema' : null,
                'verificado_automaticamente' => !empty($extractedData),
                'fecha_verificacion' => !empty($extractedData) ? now() : null,
                
                // Campos adicionales si vienen en extractedData
                'fecha_inscripcion' => $extractedData['fecha_inscripcion'] ?? null,
                'domicilio_fiscal' => $extractedData['domicilio_fiscal'] ?? null,
                'actividades_economicas' => $extractedData['actividades_economicas'] ?? null,
            ];

            // Crear el perfil fiscal
            return Auth::user()->customer->taxProfiles()->create($taxProfileData);

        } catch (\Throwable $e) {
            // Si ocurrió un error y se guardó el archivo, eliminarlo
            if ($newFilePath) {
                dispatch(function () use ($newFilePath) {
                    if (Storage::exists($newFilePath)) {
                        Storage::delete($newFilePath);
                    }
                })->afterResponse();
            }
            
            // Relanzar la excepción
            throw $e;
        }
    }

    protected function determinarTipoPersona(string $rfc): string
    {
        $rfc = Str::upper(trim($rfc));
        
        // Personas Morales: 3 letras + 6 números + 3 alfanuméricos (12 caracteres)
        // Personas Físicas: 4 letras + 6 números + 3 alfanuméricos (13 caracteres)
        
        if (strlen($rfc) === 12) {
            // RFC de persona moral
            return 'moral';
        } elseif (strlen($rfc) === 13) {
            // RFC de persona física
            return 'fisica';
        }
        
        // Si no coincide con ninguno, intentar determinar por patrón
        if (preg_match('/^[A-Z&Ñ]{4}\d{2}/', $rfc)) {
            return 'fisica';
        }
        
        if (preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc)) {
            return 'moral';
        }
        
        // Por defecto asumir física
        return 'fisica';
    }
}