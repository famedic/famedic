<?php

namespace App\Actions\Odessa;

use App\Actions\Odessa\GenerateOdessaKeyAction;
use App\DTOs\OdessaTokenData;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class DecodeOdessaTokenAction
{
    private GenerateOdessaKeyAction $generateOdessaKeyAction;

    public function __construct(GenerateOdessaKeyAction $generateOdessaKeyAction)
    {
        $this->generateOdessaKeyAction = $generateOdessaKeyAction;
    }

    public function __invoke(string $odessaToken): OdessaTokenData
    {
        $decoded = JWT::decode(
            $odessaToken,
            ($this->generateOdessaKeyAction)()
        );

        Log::info('ODESSA TOKEN DECODED', [
            'data' => (array) $decoded
        ]);

        return OdessaTokenData::fromObject($decoded);
    }
}
