<?php

namespace App\Services\MedicalCheckout;

use App\Domain\Akubica\Benefits\BenefitFailure;

final class MedicalGatewayRegistry
{
    public function get(string $provider): MedicalRemnantGateway
    {
        if (config('akubica_connectors.providers.'.$provider.'.certified') !== true) {
            throw new BenefitFailure('GATEWAY_CERTIFICATION_PENDING');
        }
        $class = match ($provider) {
            'paypal' => PayPalRemnantGateway::class,'efevoopay' => EfevooRemnantGateway::class,'odessa' => OdessaRemnantGateway::class,default => throw new BenefitFailure('GATEWAY_NOT_SUPPORTED')
        };

        return app($class);
    }
}
