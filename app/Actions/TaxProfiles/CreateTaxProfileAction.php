<?php

namespace App\Actions\TaxProfiles;

use App\Models\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateTaxProfileAction
{
    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        string $cfdiUse,
        ?UploadedFile $fiscalCertificate = null,
        ?array $extractedData = null // Agregar este parámetro
    ): TaxProfile {
        $customer = auth()->user()->customer;

        // Validar que no exista un perfil con el mismo RFC
        $existingProfile = $customer->taxProfiles()->where('rfc', $rfc)->first();
        if ($existingProfile) {
            throw new \Exception('Ya existe un perfil fiscal con este RFC.');
        }

        // Procesar el archivo de constancia fiscal
        $certificatePath = null;
        if ($fiscalCertificate) {
            $certificatePath = $fiscalCertificate->store(
                'tax-profiles/certificates',
                'private'
            );
        }

        // Calcular hash del archivo si existe
        $hashConstancia = null;
        if ($fiscalCertificate && $certificatePath) {
            $hashConstancia = hash_file('sha256', $fiscalCertificate->path());
        }

        // Crear el perfil fiscal
        $taxProfile = $customer->taxProfiles()->create([
            'name' => $name,
            'razon_social' => $extractedData['razon_social'] ?? $name, // Usar razon_social si viene de extractedData
            'rfc' => Str::upper($rfc),
            'zipcode' => $zipcode,
            'codigo_postal_original' => $extractedData['codigo_postal'] ?? $zipcode, // Guardar código postal original
            'tax_regime' => $taxRegime,
            'regimen_fiscal_original' => $extractedData['regimen_fiscal'] ?? null, // Guardar régimen original detectado
            'cfdi_use' => $cfdiUse,
            'fiscal_certificate' => $certificatePath,
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
        ]);

        return $taxProfile;
    }

    protected function determinarTipoPersona(string $rfc): string
    {
        // Personas Morales: 3 letras + 6 números + 3 alfanuméricos
        // Personas Físicas: 4 letras + 6 números + 3 alfanuméricos
        
        // Si la primera parte tiene 4 letras y termina con números, es física
        if (preg_match('/^[A-Z&Ñ]{4}\d{2}/', $rfc)) {
            return 'fisica';
        }
        
        // Si tiene 3 letras al inicio, es moral
        if (preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc)) {
            return 'moral';
        }
        
        // Por defecto asumir física
        return 'fisica';
    }
}