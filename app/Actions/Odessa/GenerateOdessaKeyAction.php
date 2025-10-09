<?php

namespace App\Actions\Odessa;

use Firebase\JWT\Key;

class GenerateOdessaKeyAction
{
    public function __invoke(): Key
    {
        return new Key(base64_decode(env('ODESSA_PUBLIC_KEY')), 'RS512');
    }
}
