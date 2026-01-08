<?php

namespace App\Console\Commands;

use App\Services\EfevooPayService;
use App\Services\TOTPService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DebugEfevooPay extends Command
{
    protected $signature = 'efevoopay:debug';
    protected $description = 'Debug completo de integración EfevooPay';
    
    public function handle(TOTPService $totpService, EfevooPayService $efevooPayService): void
    {
        $this->info('🔍 Debug completo de EfevooPay');
        $this->line(str_repeat('=', 60));
        
        // 1. Verificar configuración
        $this->info('1. 📋 Verificando configuración...');
        $this->checkConfiguration();
        
        // 2. Probar TOTP
        $this->info("\n2. 🔐 Probando TOTP...");
        $this->testTOTP($totpService);
        
        // 3. Probar generación de token
        $this->info("\n3. 🪙 Probando generación de token...");
        $this->testTokenGeneration($totpService);
        
        // 4. Probar conexión HTTP
        $this->info("\n4. 🌐 Probando conexión HTTP...");
        $this->testHttpConnection();
        
        // 5. Probar llamada API completa
        $this->info("\n5. 🚀 Probando llamada API completa...");
        $this->testApiCall($efevooPayService);
        
        $this->line(str_repeat('=', 60));
        $this->info('✅ Debug completado');
    }
    
    private function checkConfiguration(): void
    {
        $configs = [
            'EFEVOOPAY_API_KEY' => config('efevoopay.api_key'),
            'EFEVOOPAY_API_SECRET' => config('efevoopay.api_secret'),
            'EFEVOOPAY_TOTP_SECRET' => config('efevoopay.totp_secret'),
            'EFEVOOPAY_API_URL' => config('efevoopay.urls.api'),
            'APP_URL' => config('app.url'),
        ];
        
        foreach ($configs as $key => $value) {
            $status = $value ? '✅' : '❌';
            $this->line("  {$status} {$key}: " . ($value ? substr($value, 0, 20) . '...' : 'NO CONFIGURADO'));
        }
    }
    
    private function testTOTP(TOTPService $totpService): void
    {
        $secret = config('efevoopay.totp_secret');
        
        try {
            $code = $totpService->generate($secret);
            $this->line("  ✅ TOTP generado: {$code}");
            $this->line("  ⏱️  Válido por: " . $totpService->getRemainingSeconds() . " segundos");
        } catch (\Exception $e) {
            $this->error("  ❌ Error TOTP: " . $e->getMessage());
        }
    }
    
    private function testTokenGeneration(TOTPService $totpService): void
    {
        $apiKey = config('efevoopay.api_key');
        $apiSecret = config('efevoopay.api_secret');
        $totpSecret = config('efevoopay.totp_secret');
        
        try {
            $totp = $totpService->generate($totpSecret);
            $message = $apiKey . $totp;
            $token = hash_hmac('sha256', $message, $apiSecret);
            
            $this->line("  ✅ API Key: " . substr($apiKey, 0, 10) . '...');
            $this->line("  ✅ TOTP: {$totp}");
            $this->line("  ✅ Token HMAC-SHA256: " . substr($token, 0, 20) . '...');
            $this->line("  ✅ Longitud token: " . strlen($token) . " caracteres");
            
        } catch (\Exception $e) {
            $this->error("  ❌ Error generando token: " . $e->getMessage());
        }
    }
    
    private function testHttpConnection(): void
    {
        $apiUrl = config('efevoopay.urls.api');
        
        $this->line("  📡 URL API: {$apiUrl}");
        
        // Verificar si la URL es accesible
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 0) {
            $this->error("  ❌ No se puede conectar a: {$apiUrl}");
            $this->line("  ℹ️  Verifica tu conexión a internet o firewall");
        } elseif ($httpCode >= 400) {
            $this->error("  ⚠️  HTTP Code: {$httpCode} - El servidor respondió con error");
        } else {
            $this->line("  ✅ Conexión HTTP posible (Code: {$httpCode})");
        }
    }
    
    private function testApiCall(EfevooPayService $efevooPayService): void
    {
        $this->line("  🧪 Probando conexión API completa...");
        
        try {
            // Método testConnection que debemos agregar al servicio
            $result = $efevooPayService->testConnection();
            
            if ($result) {
                $this->line("  ✅ Conexión API exitosa");
            } else {
                $this->error("  ❌ Conexión API falló");
            }
            
        } catch (\Exception $e) {
            $this->error("  ❌ Error en API call: " . $e->getMessage());
            $this->line("  📋 Trace: " . $e->getTraceAsString());
        }
    }
}