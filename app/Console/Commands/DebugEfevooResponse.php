<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DebugEfevooResponse extends Command
{
    protected $signature = 'efevoo:debug-response';
    protected $description = 'Debug de respuesta de API';
    
    public function handle()
    {
        $this->info('🔍 DEBUG DE RESPUESTA DE API');
        
        // Configuración exacta
        $config = [
            'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
            'api_user' => 'Efevoo Pay',
            'api_key' => 'Hq#J0hs)jK+YqF6J',
            'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
            'clave' => '6nugHedWzw27MNB8',
            'cliente' => 'TestFAMEDIC',
            'vector' => 'MszjlcnTjGLNpNy3'
        ];
        
        // 1. Obtener TOTP y Hash
        $totp = $this->generateTOTP($config['totp_secret']);
        $hash = $this->generateHash($totp, $config['clave']);
        
        // 2. Obtener token de cliente
        $clientToken = $this->getClientTokenDebug($config, $hash);
        
        if (!$clientToken) {
            $this->error('No se pudo obtener client token');
            return 1;
        }
        
        // 3. Tokenización
        $response = $this->testTokenizationDebug($config, $clientToken);
        
        $this->info('📊 ANÁLISIS DE RESPUESTA:');
        $this->line('Código: ' . ($response['codigo'] ?? 'N/A'));
        $this->line('¿Tiene token_usuario?: ' . (isset($response['token_usuario']) ? 'SÍ' : 'NO'));
        $this->line('¿Tiene token?: ' . (isset($response['token']) ? 'SÍ' : 'NO'));
        
        if (isset($response['token_usuario'])) {
            $this->info('✅ token_usuario encontrado: ' . substr($response['token_usuario'], 0, 20) . '...');
            $this->info('   Longitud: ' . strlen($response['token_usuario']));
        }
        
        $this->newLine();
        $this->info('🎯 CONCLUSIÓN:');
        $this->line('La API devuelve: ' . (isset($response['token_usuario']) ? 'token_usuario' : (isset($response['token']) ? 'token' : 'NINGUNO')));
        
        return 0;
    }
    
    protected function getClientTokenDebug($config, $hash)
    {
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $config['api_user'],
            'X-API-KEY: ' . $config['api_key']
        ];
        
        $body = json_encode([
            'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
            'method' => 'getClientToken'
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $config['api_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['token'] ?? null;
    }
    
    protected function testTokenizationDebug($config, $clientToken)
    {
        $tarjeta = '5267772159330969';
        $expiracion = '3111';
        $montoMinimo = '1.50';
        
        $datos = [
            'track2' => $tarjeta . '=' . $expiracion,
            'amount' => $montoMinimo
        ];
        
        $encrypted = $this->encryptDataAES($datos, $config['clave'], $config['vector']);
        
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $config['api_user'],
            'X-API-KEY: ' . $config['api_key']
        ];
        
        $body = json_encode([
            'payload' => [
                'token' => $clientToken,
                'encrypt' => $encrypted
            ],
            'method' => 'getTokenize'
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $config['api_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    // ... (mantén las funciones generateTOTP, generateHash, encryptDataAES)
}