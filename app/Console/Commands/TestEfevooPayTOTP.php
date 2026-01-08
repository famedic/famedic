<?php

namespace App\Console\Commands;

use App\Services\TOTPService;
use Illuminate\Console\Command;

class TestEfevooPayTOTP extends Command
{
    protected $signature = 'efevoopay:totp 
                            {--generate : Generar un nuevo secreto}
                            {--secret= : Probar con un secreto específico}';
    
    protected $description = 'Probar generación de TOTP para EfevooPay';
    
    public function handle(TOTPService $totpService): void
    {
        if ($this->option('generate')) {
            $newSecret = $totpService->generateSecret();
            $this->info('🎯 Nuevo secreto TOTP generado:');
            $this->line($newSecret);
            $this->line('');
            $this->info('📋 Agrega esto a tu .env:');
            $this->line('EFEVOOPAY_TOTP_SECRET=' . $newSecret);
            return;
        }
        
        $secret = $this->option('secret') ?? config('efevoopay.totp_secret');
        
        if (!$secret) {
            $this->error('❌ No se encontró EFEVOOPAY_TOTP_SECRET en .env');
            $this->line('Usa --secret="TU_SECRETO" o configura la variable en .env');
            return;
        }
        
        $this->info('🔐 Probando TOTP con secreto: ' . substr($secret, 0, 10) . '...');
        $this->line('Longitud: ' . strlen($secret) . ' caracteres');
        
        // Validar formato
        $isValid = $totpService->validateSecret($secret);
        $this->line('✅ Formato válido: ' . ($isValid ? 'SÍ' : 'NO'));
        
        if (!$isValid) {
            $this->error('⚠️  El secreto no tiene un formato válido para TOTP');
            $this->line('Los secretos TOTP deben ser base32 (solo letras A-Z y números 2-7)');
            $this->line('Usa --generate para crear uno nuevo');
            return;
        }
        
        // Generar varios códigos para verificar
        $this->info('🔢 Generando códigos de prueba:');
        
        for ($i = 0; $i < 5; $i++) {
            try {
                $code = $totpService->generate($secret);
                $remaining = $totpService->getRemainingSeconds();
                
                $this->line(sprintf(
                    '  %d. Código: %s (válido por %d segundos)',
                    $i + 1,
                    $code,
                    $remaining
                ));
                
                // Esperar 5 segundos para ver cambios
                if ($i < 4) {
                    sleep(5);
                }
                
            } catch (\Exception $e) {
                $this->error('  ❌ Error: ' . $e->getMessage());
                break;
            }
        }
        
        $this->info('🎉 Prueba completada!');
    }
}