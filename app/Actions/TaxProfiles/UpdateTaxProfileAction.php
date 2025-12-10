<?php

namespace App\Actions\TaxProfiles;

use App\Models\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UpdateTaxProfileAction
{
    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        string $cfdiUse,
        TaxProfile $taxProfile,
        ?UploadedFile $fiscalCertificate = null,
        ?array $extractedData = null // Agregar este parámetro
    ): TaxProfile {
        // Verificar si el RFC está siendo cambiado y si ya existe
        if ($rfc !== $taxProfile->rfc) {
            $existingProfile = $taxProfile->customer->taxProfiles()
                ->where('rfc', Str::upper($rfc))
                ->where('id', '!=', $taxProfile->id)
                ->first();
            
            if ($existingProfile) {
                throw new \Exception('Ya existe otro perfil fiscal con este RFC.');
            }
        }

        // Procesar nuevo archivo si se proporciona
        $certificatePath = $taxProfile->fiscal_certificate;
        $hashConstancia = $taxProfile->hash_constancia;
        
        if ($fiscalCertificate) {
            // Eliminar archivo anterior si existe
            if ($taxProfile->fiscal_certificate && Storage::disk('private')->exists($taxProfile->fiscal_certificate)) {
                Storage::disk('private')->delete($taxProfile->fiscal_certificate);
            }
            
            // Guardar nuevo archivo
            $certificatePath = $fiscalCertificate->store(
                'tax-profiles/certificates',
                'private'
            );
            
            // Calcular nuevo hash
            $hashConstancia = hash_file('sha256', $fiscalCertificate->path());
        }

        // Preparar datos para actualizar
        $updateData = [
            'name' => $name,
            'razon_social' => $extractedData['razon_social'] ?? $taxProfile->razon_social ?? $name,
            'rfc' => Str::upper($rfc),
            'zipcode' => $zipcode,
            'tax_regime' => $taxRegime,
            'cfdi_use' => $cfdiUse,
            'fiscal_certificate' => $certificatePath,
            'hash_constancia' => $hashConstancia,
        ];

        // Si hay datos extraídos, actualizar campos adicionales
        if ($extractedData) {
            $updateData = array_merge($updateData, [
                'codigo_postal_original' => $extractedData['codigo_postal'] ?? $zipcode,
                'regimen_fiscal_original' => $extractedData['regimen_fiscal'] ?? $taxProfile->regimen_fiscal_original,
                'tipo_persona' => $extractedData['tipo_persona'] ?? $taxProfile->tipo_persona ?? $this->determinarTipoPersona($rfc),
                'fecha_emision_constancia' => $extractedData['fecha_emision'] ?? $taxProfile->fecha_emision_constancia,
                'estatus_sat' => $extractedData['estatus_sat'] ?? $taxProfile->estatus_sat ?? 'Desconocido',
                'tipo_persona_confianza' => $extractedData['tipo_persona_confianza'] ?? $taxProfile->tipo_persona_confianza ?? 0,
                'tipo_persona_detectado_por' => $extractedData ? 'sistema' : ($taxProfile->tipo_persona_detectado_por ?? null),
                'verificado_automaticamente' => !empty($extractedData) ? true : $taxProfile->verificado_automaticamente,
                'fecha_verificacion' => !empty($extractedData) ? now() : $taxProfile->fecha_verificacion,
                'fecha_inscripcion' => $extractedData['fecha_inscripcion'] ?? $taxProfile->fecha_inscripcion,
                'domicilio_fiscal' => $extractedData['domicilio_fiscal'] ?? $taxProfile->domicilio_fiscal,
                'actividades_economicas' => $extractedData['actividades_economicas'] ?? $taxProfile->actividades_economicas,
            ]);
        }

        $taxProfile->update($updateData);

        return $taxProfile->fresh();
    }

    protected function determinarTipoPersona(string $rfc): string
    {
        if (preg_match('/^[A-Z&Ñ]{4}\d{2}/', $rfc)) {
            return 'fisica';
        }
        
        if (preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc)) {
            return 'moral';
        }
        
        return 'fisica';
    }
}