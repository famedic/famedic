<?php

namespace App\Actions\MedicalAttention;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AuthorizationAction
{
    public function __invoke(): Response
    {
        $url = config('services.murguia.url') . 'api/Security/Auth';

        $payload = [
            'usuario' => config('services.murguia.username'),
            'contraseña' => config('services.murguia.password'),
        ];

        $response = Http::post($url, $payload);

        return $response;
    }
}
