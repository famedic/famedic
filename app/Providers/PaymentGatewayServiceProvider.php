<?php

namespace App\Providers;

use App\Actions\Payments\ChargePaymentMethodAction;
use App\Services\Payments\EfevooPaymentGateway;
use App\Services\Payments\HeyBancoPaymentGateway;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HeyBancoClient::class);
        $this->app->singleton(EfevooPaymentGateway::class);
        $this->app->singleton(HeyBancoPaymentGateway::class);
        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->singleton(ChargePaymentMethodAction::class);
    }
}
