<?php

namespace App\Console\Commands;

use App\Services\EfevooPayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use ReflectionClass;

class TestExactClone extends Command
{
    protected $signature = 'efevoo:test-exact-clone';
    protected $description = 'Clonación EXACTA del script funcional';
    
    public function handle()
    {
        $this->info('🔬 CLONACIÓN EXACTA DEL SCRIPT FUNCIONAL');
        $this->newLine();
        
        $service = app(EfevooPayService::class);
        
        // Usar reflexión para acceder al método nuevo
        $reflection = new ReflectionClass($service);
        
        if (!$reflection->hasMethod('tokenizeCardExact')) {
            $this->error('❌ El método tokenizeCardExact no existe');
            $this->info('   Crea el método primero siguiendo las instrucciones');
            return 1;
        }
        
        $method = $reflection->getMethod('tokenizeCardExact');
        $method->setAccessible(true);
        
        // Datos EXACTOS de tu script
        $cardData = [
            'card_number' => '5267772159330969',
            'expiration' => '3111', // MMYY
            'card_holder' => 'TEST USER',
            'amount' => 1.50,
        ];
        
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Tarjeta', substr($cardData['card_number'], 0, 6) . '******' . substr($cardData['card_number'], -4)],
                ['Expiración', $cardData['expiration'] . ' (MMYY = Nov 2031)'],
                ['Monto', '$' . $cardData['amount'] . ' MXN'],
            ]
        );
        
        $this->warn('⚠️  Se hará un cargo REAL de $1.50 MXN');
        
        if (!$this->confirm('¿Continuar con clonación exacta?')) {
            return 0;
        }
        
        $this->info('Ejecutando clonación EXACTA...');
        
        try {
            $result = $method->invoke($service, $cardData, 1);
            
            if ($result['success']) {
                $this->info('✅ CLONACIÓN EXITOSA');
                $this->line('   Token ID: ' . ($result['token_id'] ?? 'N/A'));
                $this->line('   Código: ' . ($result['codigo'] ?? 'N/A'));
                $this->line('   Mensaje: ' . ($result['message'] ?? 'N/A'));
                
                if (isset($result['card_token'])) {
                    $this->line('   Card Token: ' . substr($result['card_token'], 0, 20) . '...');
                }
                
                if (isset($result['data'])) {
                    $this->info('   📊 Respuesta completa:');
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
                }
                
                // Probar pago con token obtenido
                if (isset($result['token_id'])) {
                    $this->testPaymentWithToken($result['token_id']);
                }
                
            } else {
                $this->error('❌ CLONACIÓN FALLIDA');
                $this->error('   Error: ' . ($result['message'] ?? 'N/A'));
                $this->error('   Código: ' . ($result['codigo'] ?? 'N/A'));
                
                if (isset($result['data'])) {
                    $this->line('   Respuesta: ' . json_encode($result['data']));
                }
            }
            
        } catch (\Exception $e) {
            $this->error('❌ EXCEPCIÓN: ' . $e->getMessage());
            Log::error('Error en clonación exacta', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        
        return 0;
    }
    
    protected function testPaymentWithToken($tokenId)
    {
        $this->newLine();
        $this->info('💳 Probando pago con token obtenido...');
        
        if (!$this->confirm('¿Probar pago REAL con token? ($10.00 MXN)')) {
            return;
        }
        
        $service = app(EfevooPayService::class);
        
        $paymentData = [
            'amount' => 10.00,
            'cav' => 'TEST-' . time(),
            'referencia' => 'PAY-' . time(),
            'description' => 'Pago prueba clonación',
            'msi' => 0,
        ];
        
        $this->info('Procesando pago...');
        
        $result = $service->processPayment($paymentData, $tokenId);
        
        if ($result['success']) {
            $this->info('✅ PAGO EXITOSO');
            $this->line('   Código: ' . ($result['codigo'] ?? 'N/A'));
            $this->line('   Mensaje: ' . ($result['message'] ?? 'N/A'));
        } else {
            $this->error('❌ PAGO FALLIDO');
            $this->error('   Error: ' . ($result['message'] ?? 'N/A'));
        }
    }
}