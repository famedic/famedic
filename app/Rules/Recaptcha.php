<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Recaptcha implements Rule
{
    public function passes($attribute, $value): bool
    {
        Log::debug('🔍 Validando reCAPTCHA', [
            'token_length' => strlen($value),
            'token_preview' => $value ? substr($value, 0, 50) . '...' : 'empty',
            'ip' => request()->ip(),
            'environment' => app()->environment(),
            'secret_key_set' => !empty(env('RECAPTCHA_SECRET_KEY')),
        ]);

        // Permitir en desarrollo/testing sin token
        if (app()->environment(['local', 'testing']) && empty($value)) {
            Log::info('✅ reCAPTCHA skipped in development environment');
            return true;
        }

        // Si no hay token, falla
        if (empty($value)) {
            Log::warning('❌ Empty reCAPTCHA token received');
            return false;
        }

        try {
            $secretKey = env('RECAPTCHA_SECRET_KEY');
            
            if (empty($secretKey)) {
                Log::error('❌ RECAPTCHA_SECRET_KEY no está configurada');
                return false;
            }
            
            Log::debug('📤 Enviando verificación a Google', [
                'secret_key_length' => strlen($secretKey),
                'secret_key_preview' => substr($secretKey, 0, 10) . '...',
                'site_key' => env('RECAPTCHA_SITE_KEY'),
            ]);

            $response = Http::timeout(10)->asForm()->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $secretKey,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]
            );

            Log::debug('📥 Respuesta de Google recibida', [
                'status_code' => $response->status(),
                'success' => $response->successful(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::warning('❌ reCAPTCHA API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            $result = $response->json();

            Log::debug('📊 Resultado de verificación', $result);

            // Validar que sea exitoso
            if (!isset($result['success']) || $result['success'] !== true) {
                Log::warning('❌ reCAPTCHA verification failed', [
                    'result' => $result,
                    'error-codes' => $result['error-codes'] ?? [],
                    'hostname' => $result['hostname'] ?? null,
                ]);
                return false;
            }

            Log::info('✅ reCAPTCHA validation successful', [
                'hostname' => $result['hostname'] ?? null,
                'challenge_ts' => $result['challenge_ts'] ?? null,
                'action' => $result['action'] ?? null,
            ]);
            return true;

        } catch (\Exception $e) {
            Log::error('💥 reCAPTCHA validation exception: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            // En desarrollo, permitir para testing
            if (app()->environment(['local', 'testing'])) {
                Log::info('✅ Skipping reCAPTCHA in local/testing due to exception');
                return true;
            }
            
            return false;
        }
    }

    public function message(): string
    {
        return 'La verificación de seguridad falló. Por favor, intenta de nuevo.';
    }
}