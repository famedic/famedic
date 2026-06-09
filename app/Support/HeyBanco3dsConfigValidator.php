<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class HeyBanco3dsConfigValidator
{
    public static function validate(): void
    {
        if (! config('heybanco.3ds_enabled', false)) {
            return;
        }

        $missing = [];

        if (empty(config('heybanco.3ds_url'))) {
            $missing[] = 'HEYBANCO_3DS_URL';
        }

        if (empty(config('heybanco.3ds_media_id'))) {
            $missing[] = 'HEYBANCO_3DS_MEDIA_ID';
        }

        if (config('heybanco.3ds_secure_api', true) && empty(config('heybanco.3ds_secret_key'))) {
            $missing[] = 'HEYBANCO_3DS_SECRET_KEY';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Hey Banco 3DS habilitado pero faltan variables: ' . implode(', ', $missing)
            );
        }

        $env = config('heybanco.env', 'sandbox');
        $mode = strtoupper((string) config('heybanco.mode', 'AUT'));

        if ($env === 'production' && $mode !== 'PRD') {
            Log::warning('[HeyBanco3DS] HEYBANCO_MODE debería ser PRD en producción.', [
                'mode' => $mode,
            ]);
        }

        if ($env !== 'production' && ! in_array($mode, ['AUT', 'DEC', 'RND', 'PRD'], true)) {
            Log::warning('[HeyBanco3DS] HEYBANCO_MODE no reconocido para sandbox.', [
                'mode' => $mode,
            ]);
        }
    }
}
