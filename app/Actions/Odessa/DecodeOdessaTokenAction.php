<?php

namespace App\Actions\Odessa;

use App\Actions\Odessa\GenerateOdessaKeyAction;
use App\DTOs\OdessaTokenData;
use Firebase\JWT\JWT;

class DecodeOdessaTokenAction
{
    private GenerateOdessaKeyAction $generateOdessaKeyAction;

    public function __construct(GenerateOdessaKeyAction $generateOdessaKeyAction)
    {
        $this->generateOdessaKeyAction = $generateOdessaKeyAction;
    }

    public function __invoke(string $odessaToken): OdessaTokenData
    {
        return OdessaTokenData::fromObject(
            JWT::decode(
                $odessaToken,
                ($this->generateOdessaKeyAction)()
            )
        );
    }
}
