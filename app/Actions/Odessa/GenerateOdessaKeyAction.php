<?php

namespace App\Actions\Odessa;

use Firebase\JWT\Key;

class GenerateOdessaKeyAction
{
    public function __invoke(): Key
    {
        return new Key(base64_decode((string) config('services.odessa.public_key')), 'RS512');
    }
}
