<?php

namespace App\Services\Murguia;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MurguiaService
{
    private const TOKEN_CACHE_KEY = 'murguia_web_affiliate_access_token';

    public function getIframeUrlForUser(User $user): string
    {
        $customer = $user->customer;
        $affKey = $customer?->medical_attention_identifier;
        Log::info('Murguia service: preparando URL para usuario.', [
            'user_id' => $user->id,
            'has_aff_key' => (bool) $affKey,
        ]);

        if (!$affKey) {
            throw new Exception('No fue posible generar la llave de afiliado.');
        }

        $query = [
            'acId' => config('services.murguia_web_affiliate.ac_id', 23),
            'affKey' => $affKey,
            'affName' => trim($user->full_name),
            'username' => config('services.murguia_web_affiliate.username', 'ODESSAMX'),
            'plId' => config('services.murguia_web_affiliate.pl_id', 60),
            'token' => $this->getAccessTokenWithRetry(),
            'client-id' => config('services.murguia_web_affiliate.client_id', 25),
        ];

        $baseUrl = rtrim(config('services.murguia_web_affiliate.iframe_base_url'), '/');
        Log::info('Murguia service: URL base obtenida.', [
            'user_id' => $user->id,
            'base_url' => $baseUrl,
        ]);

        return $baseUrl . '/?' . http_build_query($query);
    }

    public function getAccessTokenWithRetry(): string
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);

        if ($token) {
            Log::info('Murguia service: token obtenido de cache.');
            return $token;
        }

        try {
            Log::info('Murguia service: solicitando token nuevo.');
            return $this->requestAccessTokenAndCache();
        } catch (\Throwable $exception) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            Log::warning('Murguia service: primer intento de token fallo, reintentando.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->requestAccessTokenAndCache();
        }
    }

    private function requestAccessTokenAndCache(): string
    {
        $response = Http::timeout(15)->post(
            config('services.murguia_web_affiliate.token_url'),
            [
                'username' => config('services.murguia_web_affiliate.username', 'ODESSAMX'),
                'password' => config('services.murguia_web_affiliate.password', '123456App&'),
            ]
        );

        if ($response->failed()) {
            Log::error('Murguia service: fallo autenticacion HTTP.', [
                'status' => $response->status(),
            ]);
            throw new Exception('No fue posible autenticar con Murguia.');
        }

        $token = $response->json('access');

        if (!is_string($token) || $token === '') {
            throw new Exception('Murguia no devolvio un token valido.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes(30));
        Log::info('Murguia service: token recibido y guardado en cache.');

        return $token;
    }
}
