<?php

namespace App\Providers;

use App\Support\LocalCheckoutQa;
use App\Support\LocalQaMailManager;
use App\Support\LocalQaOpenAiClient;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

final class LocalCheckoutQaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! LocalCheckoutQa::enabled()) {
            return;
        }
        $this->app->extend('mail.manager', fn ($manager, $app) => new LocalQaMailManager($app));
        $this->app->bind(OpenAiClient::class, LocalQaOpenAiClient::class);
        // Do not leave QA purchase automation jobs waiting to execute after QA is disabled.
        config(['order_automation.drivers' => [],
            'services.activecampaign.enabled' => false,
            'services.activecampaign.cart_site_events_enabled' => false,
            'services.activecampaign.cart_tag_remove_enabled' => false,
            'services.activecampaign.cart_outbox_enabled' => false,
            'services.activecampaign.tag_abandoned_carts_enabled' => false,
            'services.activecampaign.coupons_expiring_enabled' => false]);
    }

    public function boot(): void
    {
        if (! LocalCheckoutQa::enabled()) {
            return;
        }
        if ($this->app->runningInConsole() && in_array($_SERVER['argv'][1] ?? '',
            ['queue:work', 'queue:listen', 'queue:retry', 'horizon', 'schedule:run', 'schedule:work'], true)) {
            throw new \RuntimeException('local_qa_use_guarded_worker_or_focal_reconcile');
        }
        // Covers synchronous services, observers, jobs and listeners using Laravel's HTTP client.
        // Fixed diagnostic only; never include provider URL, token, clinical content or request body.
        Http::globalRequestMiddleware(function ($request) {
            throw new \RuntimeException('local_qa_outbound_http_blocked');
        });
    }
}
