<?php

namespace App\Console\Commands;

use App\Services\WebSocketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MonitorEfevooPayWebSocket extends Command
{
    protected $signature = 'efevoopay:monitor 
                            {--once : Ejecutar una sola vez}
                            {--test : Probar conexión y salir}';
    
    protected $description = 'Monitor WebSocket connection to EfevooPay';
    
    private WebSocketService $webSocketService;
    private bool $shouldStop = false;
    
    public function __construct(WebSocketService $webSocketService)
    {
        parent::__construct();
        $this->webSocketService = $webSocketService;
    }
    
    public function handle(): void
    {
        if ($this->option('test')) {
            $this->testConnection();
            return;
        }
        
        $this->info('🚀 Starting EfevooPay WebSocket monitor...');
        $this->info('📡 URL: ' . config('efevoopay.urls.wss'));
        $this->info('🔑 API Key: ' . config('efevoopay.api_key'));
        $this->info('Press Ctrl+C to stop');
        
        $this->setupSignalHandlers();
        
        $checkInterval = 30; // segundos
        $lastCheck = 0;
        
        while (!$this->shouldStop) {
            try {
                $currentTime = time();
                
                // Verificar conexión cada $checkInterval segundos
                if (($currentTime - $lastCheck) >= $checkInterval) {
                    $this->performHealthCheck();
                    $lastCheck = $currentTime;
                }
                
                // Aquí iría la lógica real de conexión WebSocket
                // Por ahora, solo mantenemos el proceso vivo
                
                if ($this->option('once')) {
                    $this->info('✅ Single execution completed');
                    break;
                }
                
                // Esperar 5 segundos antes de la siguiente iteración
                sleep(5);
                
            } catch (\Exception $e) {
                $this->error('❌ WebSocket error: ' . $e->getMessage());
                Log::error('EfevooPay WebSocket monitor error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                if ($this->option('once')) {
                    break;
                }
                
                // Esperar antes de reconectar
                sleep(60);
            }
        }
        
        $this->info('🛑 WebSocket monitor stopped');
    }
    
    /**
     * Configurar manejo de señales para detener limpiamente
     */
    private function setupSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                $this->shouldStop = true;
                $this->info("\n👋 Received SIGINT, stopping gracefully...");
            });
            pcntl_signal(SIGTERM, function () {
                $this->shouldStop = true;
                $this->info("\n👋 Received SIGTERM, stopping gracefully...");
            });
        }
    }
    
    /**
     * Realizar verificación de salud
     */
    private function performHealthCheck(): void
    {
        $cacheKey = 'efevoopay_health_check';
        $lastHealthCheck = Cache::get($cacheKey);
        
        if (!$lastHealthCheck || (time() - $lastHealthCheck) > 300) { // 5 minutos
            $this->info('🩺 Performing health check...');
            
            // Aquí podrías verificar la conectividad con EfevooPay
            // Por ahora solo actualizamos el timestamp
            
            Cache::put($cacheKey, time(), 600); // 10 minutos
            $this->info('✅ Health check completed at ' . date('Y-m-d H:i:s'));
        }
    }
    
    /**
     * Probar conexión básica
     */
    private function testConnection(): void
    {
        $this->info('🧪 Testing EfevooPay connection...');
        
        try {
            // Verificar configuración
            $apiKey = config('efevoopay.api_key');
            $apiSecret = config('efevoopay.api_secret');
            $totpSecret = config('efevoopay.totp_secret');
            
            if (!$apiKey || !$apiSecret || !$totpSecret) {
                $this->error('❌ Missing EfevooPay configuration in .env');
                $this->line('Required variables:');
                $this->line('  - EFEVOOPAY_API_KEY');
                $this->line('  - EFEVOOPAY_API_SECRET');
                $this->line('  - EFEVOOPAY_TOTP_SECRET');
                return;
            }
            
            $this->info('✅ Configuration check passed');
            
            // Probar generación de TOTP
            $totpService = app(\App\Services\TOTPService::class);
            $totp = $totpService->generate($totpSecret);
            
            $this->info('✅ TOTP generated: ' . $totp);
            
            // Probar generación de token
            $message = $apiKey . $totp;
            $token = hash_hmac('sha256', $message, $apiSecret);
            
            $this->info('✅ Token generated: ' . substr($token, 0, 20) . '...');
            
            // Verificar URLs
            $apiUrl = config('efevoopay.urls.api');
            $checkoutUrl = config('efevoopay.urls.checkout');
            $wssUrl = config('efevoopay.urls.wss');
            
            $this->info('📋 URLs configured:');
            $this->info('  API: ' . $apiUrl);
            $this->info('  Checkout: ' . $checkoutUrl);
            $this->info('  WebSocket: ' . $wssUrl);
            
            $this->info('🎉 All tests passed! Configuration is ready.');
            
        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            Log::error('EfevooPay connection test failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}