<?php

namespace App\Console\Commands;

use App\Models\TaxProfile;
use App\Services\ConstanciaFiscalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessExistingConstancias extends Command
{
    protected $signature = 'constancias:process-existing';
    protected $description = 'Procesa constancias existentes para extraer datos';
    
    public function handle()
    {
        $service = new ConstanciaFiscalService();
        $profiles = TaxProfile::whereNull('verificado_automaticamente')->get();
        
        $this->info("Procesando {$profiles->count()} perfiles...");
        
        $bar = $this->output->createProgressBar($profiles->count());
        
        foreach ($profiles as $profile) {
            try {
                if (!Storage::exists($profile->fiscal_certificate)) {
                    $this->warn("Archivo no encontrado para perfil {$profile->id}");
                    $bar->advance();
                    continue;
                }
                
                // Crear archivo temporal
                $tempPath = tempnam(sys_get_temp_dir(), 'constancia_');
                file_put_contents($tempPath, Storage::get($profile->fiscal_certificate));
                
                // Procesar
                $resultado = $service->procesarConstancia(
                    new \Illuminate\Http\UploadedFile($tempPath, basename($profile->fiscal_certificate))
                );
                
                if ($resultado['success']) {
                    $datos = $resultado['data'];
                    
                    $profile->update([
                        'razon_social' => $datos['razon_social'] ?? $datos['nombre'] ?? null,
                        'tipo_persona' => $datos['tipo_persona'] ?? 'desconocido',
                        'codigo_postal_original' => $datos['codigo_postal'] ?? null,
                        'regimen_fiscal_original' => $datos['regimen_fiscal'] ?? null,
                        'fecha_emision_constancia' => $datos['fecha_emision'],
                        'fecha_inscripcion' => $datos['fecha_inscripcion'] 
                            ? \Carbon\Carbon::createFromFormat('d/m/Y', $datos['fecha_inscripcion'])
                            : null,
                        'estatus_sat' => $datos['estatus_sat'],
                        'domicilio_fiscal' => $datos['domicilio_fiscal'],
                        'actividades_economicas' => $datos['actividades_economicas'],
                        'tipo_persona_confianza' => $datos['tipo_persona_confianza'],
                        'tipo_persona_detectado_por' => $datos['tipo_persona_detectado_por'],
                        'hash_constancia' => hash_file('sha256', $tempPath),
                        'verificado_automaticamente' => true,
                        'fecha_verificacion' => now(),
                    ]);
                    
                    $this->info("✓ Perfil {$profile->id} procesado");
                } else {
                    $this->warn("✗ Error en perfil {$profile->id}: {$resultado['error']}");
                }
                
                // Eliminar archivo temporal
                unlink($tempPath);
                
            } catch (\Exception $e) {
                $this->error("Error procesando perfil {$profile->id}: {$e->getMessage()}");
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->info('Proceso completado.');
    }
}