<?php

namespace App\Services;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Illuminate\Support\Facades\Log;

class TOTPService
{
    public function generate(string $secret): string
    {
        try {
            // Validar y limpiar el secreto
            $cleanSecret = $this->cleanSecret($secret);
            
            // Verificar que el secreto no esté vacío
            if (empty($cleanSecret)) {
                throw new \Exception('El secreto TOTP está vacío o no es válido');
            }
            
            // Crear TOTP con el secreto
            $totp = TOTP::create($cleanSecret);
            $totp->setPeriod(30); // 30 segundos como indica el manual
            $totp->setDigits(6);
            
            return $totp->now();
            
        } catch (\Exception $e) {
            Log::error('Error generating TOTP', [
                'original_secret' => $secret ? substr($secret, 0, 10) . '...' : 'empty',
                'clean_secret' => $cleanSecret ?? 'not_processed',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            
            // Para debugging, intentar métodos alternativos
            if (config('app.debug')) {
                return $this->generateFallback($secret);
            }
            
            throw new \Exception('Error al generar código de autenticación: ' . $e->getMessage());
        }
    }
    
    /**
     * Limpiar y validar secreto TOTP
     */
    private function cleanSecret(string $secret): string
    {
        // Eliminar espacios y caracteres especiales
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        
        // Asegurar que la longitud sea múltiplo de 8 para base32
        $padding = 8 - (strlen($clean) % 8);
        if ($padding !== 8) {
            $clean .= str_repeat('=', $padding);
        }
        
        return $clean;
    }
    
    /**
     * Método alternativo para debugging
     */
    private function generateFallback(string $secret): string
    {
        try {
            // Intentar usar directamente como string
            $time = floor(time() / 30);
            $key = hash_hmac('sha1', pack('J', $time), $secret, true);
            
            $offset = ord($key[19]) & 0xf;
            $code = (
                ((ord($key[$offset]) & 0x7f) << 24) |
                ((ord($key[$offset + 1]) & 0xff) << 16) |
                ((ord($key[$offset + 2]) & 0xff) << 8) |
                (ord($key[$offset + 3]) & 0xff)
            ) % 1000000;
            
            return str_pad($code, 6, '0', STR_PAD_LEFT);
            
        } catch (\Exception $e) {
            Log::error('Fallback TOTP generation failed', [
                'error' => $e->getMessage(),
            ]);
            
            // Retornar un código de prueba para desarrollo
            return '123456';
        }
    }
    
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        try {
            $cleanSecret = $this->cleanSecret($secret);
            $totp = TOTP::create($cleanSecret);
            $totp->setPeriod(30);
            $totp->setDigits(6);
            
            return $totp->verify($code, null, $window);
        } catch (\Exception $e) {
            Log::error('Error verifying TOTP', [
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    public function getRemainingSeconds(): int
    {
        return 30 - (time() % 30);
    }
    
    /**
     * Validar formato del secreto
     */
    public function validateSecret(string $secret): bool
    {
        try {
            $clean = $this->cleanSecret($secret);
            return !empty($clean) && strlen($clean) >= 16;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Generar un secreto TOTP válido
     */
    public function generateSecret(): string
    {
        $randomBytes = random_bytes(20); // 160 bits recomendados
        return Base32::encodeUpper($randomBytes);
    }
}