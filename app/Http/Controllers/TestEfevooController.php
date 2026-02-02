<?php

namespace App\Http\Controllers;

use App\Services\EfevooPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TestEfevooController extends Controller
{
    protected $efevooService;
    
    public function __construct(EfevooPayService $efevooService)
    {
        $this->efevooService = $efevooService;
    }
    
    public function testConnection()
    {
        Log::info('=== TEST EFEVOO PAY CONNECTION ===');
        
        // 1. Probar configuración
        $config = config('efevoopay');
        $configSummary = [
            'environment' => $config['environment'],
            'api_url' => $config['api_url'],
            'cliente' => $config['cliente'],
            'api_user' => !empty($config['api_user']) ? 'CONFIGURADO' : 'NO CONFIGURADO',
            'totp_secret' => !empty($config['totp_secret']) ? 'CONFIGURADO' : 'NO CONFIGURADO',
            'clave' => !empty($config['clave']) ? 'CONFIGURADO' : 'NO CONFIGURADO',
            'vector' => !empty($config['vector']) ? 'CONFIGURADO' : 'NO CONFIGURADO',
        ];
        
        Log::info('Configuración', $configSummary);
        
        // 2. Probar generación de TOTP
        try {
            $reflection = new \ReflectionClass($this->efevooService);
            $method = $reflection->getMethod('generateTOTP');
            $method->setAccessible(true);
            $totp = $method->invoke($this->efevooService);
            
            Log::info('TOTP generado', ['totp' => $totp]);
            
            // 3. Probar hash
            $hashMethod = $reflection->getMethod('generateHash');
            $hashMethod->setAccessible(true);
            $hash = $hashMethod->invoke($this->efevooService, $totp);
            
            Log::info('Hash generado', ['hash_preview' => substr($hash, 0, 20) . '...']);
        } catch (\Exception $e) {
            Log::error('Error en generación', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
        
        // 4. Probar conexión HTTP básica
        $client = new \GuzzleHttp\Client(['timeout' => 10, 'verify' => false]);
        try {
            $response = $client->get($config['api_url'], ['http_errors' => false]);
            Log::info('Conexión HTTP', ['status' => $response->getStatusCode()]);
        } catch (\Exception $e) {
            Log::error('Error de conexión HTTP', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Conexión HTTP fallida: ' . $e->getMessage()], 500);
        }
        
        // 5. Probar obtención de token
        $tokenResult = $this->efevooService->getClientToken(true);
        
        Log::info('Resultado token', [
            'success' => $tokenResult['success'] ?? false,
            'codigo' => $tokenResult['codigo'] ?? null,
            'message' => $tokenResult['message'] ?? null,
        ]);
        
        if ($tokenResult['success']) {
            Log::info('Token obtenido', [
                'token_preview' => substr($tokenResult['token'], 0, 20) . '...',
                'cached' => $tokenResult['cached'] ?? false,
            ]);
        }
        
        // 6. Intentar tokenización con tarjeta real
        $testCard = [
            'card_number' => '4111111111111111', // Tarjeta de prueba Visa
            'expiration' => '1230', // Diciembre 2030
            'card_holder' => 'TEST USER',
            'amount' => 1.50, // $1.50 MXN - cargo real de verificación
        ];
        
        Log::info('Intentando tokenización real', [
            'last_four' => substr($testCard['card_number'], -4),
            'amount' => $testCard['amount'],
        ]);
        
        $tokenizeResult = $this->efevooService->tokenizeCard($testCard, 1);
        
        Log::info('Resultado tokenización', [
            'success' => $tokenizeResult['success'] ?? false,
            'message' => $tokenizeResult['message'] ?? null,
            'codigo' => $tokenizeResult['codigo'] ?? null,
            'transaction_id' => $tokenizeResult['transaction']->id ?? null,
        ]);
        
        return response()->json([
            'config' => $configSummary,
            'totp' => $totp,
            'hash_preview' => substr($hash, 0, 20) . '...',
            'connection_status' => $response->getStatusCode(),
            'token_result' => [
                'success' => $tokenResult['success'],
                'codigo' => $tokenResult['codigo'] ?? null,
                'message' => $tokenResult['message'] ?? null,
                'has_token' => !empty($tokenResult['token']),
            ],
            'tokenization_result' => [
                'success' => $tokenizeResult['success'] ?? false,
                'message' => $tokenizeResult['message'] ?? null,
                'codigo' => $tokenizeResult['codigo'] ?? null,
                'transaction_created' => isset($tokenizeResult['transaction']),
            ],
            'logs' => 'Revisa storage/logs/laravel.log para detalles completos',
        ]);
    }
    
    public function testManualToken()
    {
        // Método para probar con datos manuales
        $data = request()->validate([
            'card_number' => 'required|string|size:16',
            'expiration' => 'required|string|size:4',
            'card_holder' => 'required|string|max:100',
        ]);
        
        $cardData = [
            'card_number' => $data['card_number'],
            'expiration' => $data['expiration'],
            'card_holder' => $data['card_holder'],
            'amount' => 1.50, // $1.50 MXN
        ];
        
        Log::info('Tokenización manual', [
            'last_four' => substr($cardData['card_number'], -4),
            'expiration' => $cardData['expiration'],
        ]);
        
        $result = $this->efevooService->tokenizeCard($cardData, auth()->id());
        
        return response()->json($result);
    }
}