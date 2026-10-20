<?php

namespace Tests\Unit;

use App\Support\LocalCheckoutQa;
use App\Support\LocalQaMailManager;
use App\Support\LocalQaTransport;
use App\Providers\LocalCheckoutQaServiceProvider;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class LocalCheckoutQaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('env', 'local');
        config(['local-checkout-qa.enabled' => true, 'local-checkout-qa.recipient' => 'qa@example.test']);
    }

    public static function badRecipients(): array
    {
        return [['to'], ['cc'], ['bcc'], ['multiple'], ['envelope']];
    }

    #[Test, DataProvider('badRecipients')]
    public function blocks_before_transport_for_every_recipient_channel(string $channel): void
    {
        $inner = new ArrayTransport;
        $guard = new LocalQaTransport($inner);
        $email = (new Email)->from('sender@example.test')->to('qa@example.test')->text('Synthetic QA');
        $envelope = null;
        match ($channel) {
            'to' => $email->to('other@example.test'),
            'cc' => $email->cc('other@example.test'),
            'bcc' => $email->bcc('other@example.test'),
            'multiple' => $email->to('qa@example.test', 'other@example.test'),
            'envelope' => $envelope = new Envelope(new Address('sender@example.test'), [new Address('other@example.test')]),
        };
        try { $guard->send($email, $envelope); $this->fail('Leak'); }
        catch (\RuntimeException $e) { $this->assertSame('local_qa_recipient_blocked', $e->getMessage()); }
        $this->assertCount(0, $inner->messages());
    }

    #[Test]
    public function allows_only_exact_qa_and_fails_closed_when_missing(): void
    {
        $inner = new ArrayTransport;
        $guard = new LocalQaTransport($inner);
        $message = (new Email)->from('sender@example.test')->to('qa@example.test')->text('Synthetic QA');
        $guard->send($message);
        $this->assertCount(1, $inner->messages());
        config(['local-checkout-qa.recipient' => '']);
        $this->expectExceptionMessage('local_qa_recipient_required');
        $guard->send($message);
    }

    #[Test]
    public function all_laravel_mailers_including_on_demand_use_guard(): void
    {
        $manager = new LocalQaMailManager(app());
        $inner = new ArrayTransport;
        $manager->extend('smtp', fn () => $inner);
        $mailer = $manager->build(['transport' => 'smtp', 'host' => 'smtp.gmail.com']);
        $this->assertInstanceOf(LocalQaTransport::class, $mailer->getSymfonyTransport());
        $this->expectExceptionMessage('local_qa_recipient_blocked');
        $mailer->raw('Synthetic QA', fn ($message) => $message->from('sender@example.test')->to('other@example.test'));
    }

    #[Test]
    public function alternative_transports_are_rejected(): void
    {
        $manager = new LocalQaMailManager(app());
        foreach (['ses', 'sendmail', 'mailgun', 'failover', 'roundrobin', 'custom'] as $driver) {
            try { $manager->createSymfonyTransport(['transport' => $driver]); $this->fail('Transport leak'); }
            catch (\RuntimeException $e) { $this->assertSame('local_qa_transport_blocked', $e->getMessage()); }
        }
    }

    #[Test]
    public function provider_blocks_http_even_with_a_fake_successful_response(): void
    {
        $provider = new LocalCheckoutQaServiceProvider(app());
        $provider->register(); $provider->boot();
        Http::fake(['*' => Http::response(['contact' => ['id' => 99]])]);
        foreach (['https://account.api-us1.com/api/3/contact/sync', 'https://api.openai.com/v1/chat/completions',
            'https://api-m.sandbox.paypal.com/v1/oauth2/token', 'https://gda.example.test', 'https://odessa.example.test'] as $url) {
            try { Http::post($url); $this->fail('Outbound leak'); }
            catch (\RuntimeException $e) { $this->assertSame('local_qa_outbound_http_blocked', $e->getMessage()); }
        }
        Http::assertNothingSent();
        $this->assertSame([], config('order_automation.drivers'));
    }

    #[Test]
    public function mode_never_activates_outside_local(): void
    {
        foreach (['staging', 'production', 'testing'] as $environment) {
            app()->instance('env', $environment);
            $this->assertFalse(LocalCheckoutQa::enabled());
        }
    }

    #[Test]
    public function qa_does_not_register_global_navigation_or_csp_restrictions(): void
    {
        $widgets = config('famedic.support_widgets.enabled');
        (new LocalCheckoutQaServiceProvider(app()))->register();
        $this->assertSame($widgets, config('famedic.support_widgets.enabled'));
    }

    public static function normalNavigation(): array
    {
        return [['/qa-navigation-probe', false], ['/medical-attention/qa-navigation-probe', false], ['/qa-navigation-redirect', true]];
    }

    #[Test, DataProvider('normalNavigation')]
    public function qa_preserves_navigation_and_the_original_response_policy(string $path, bool $redirect): void
    {
        config(['local-checkout-qa.recipient' => '']);
        $this->withoutMiddleware(\App\Http\Middleware\HandleInertiaRequests::class);
        $policy = "default-src 'self'; img-src https://images.example.test";
        \Illuminate\Support\Facades\Route::middleware('web')->get($path, function () use ($redirect, $policy) {
            $response = $redirect ? redirect()->away('https://example.test/normal-destination') : response('normal-navigation');
            return $response->header('Content-Security-Policy', $policy);
        });
        $response = $this->get($path);
        $response->assertStatus($redirect ? 302 : 200)
            ->assertHeader('Content-Security-Policy', $policy)
            ->assertHeaderMissing('X-Local-QA-Config');
        if ($redirect) { $response->assertRedirect('https://example.test/normal-destination'); }
        else { $response->assertSee('normal-navigation'); }
    }

    #[Test]
    public function qa_off_preserves_mailer_services_and_http_configuration(): void
    {
        config(['local-checkout-qa.enabled' => false, 'services.activecampaign.enabled' => true]);
        $manager = app('mail.manager');
        $drivers = config('order_automation.drivers');
        $provider = new LocalCheckoutQaServiceProvider(app());
        $provider->register(); $provider->boot();
        $this->assertFalse(LocalCheckoutQa::enabled());
        $this->assertSame($manager, app('mail.manager'));
        $this->assertTrue(config('services.activecampaign.enabled'));
        $this->assertSame($drivers, config('order_automation.drivers'));
        $this->assertNotInstanceOf(\App\Support\LocalQaOpenAiClient::class, app(\App\Services\OpenAi\OpenAiClient::class));
        Http::fake(['*' => Http::response(['synthetic' => true])]);
        $this->assertTrue(Http::get('https://example.test/qa-off-probe')->successful());
        Http::assertSentCount(1); // Fake response only: no real network.
    }

    #[Test]
    public function gda_uses_existing_local_simulator_without_a_request(): void
    {
        config(['app.env' => 'local']);
        Http::fake();
        $result = app(\App\Actions\Laboratories\CreateGDAQuotationAction::class)(
            new \App\Models\Customer, new \App\Models\Address, new \App\Models\Contact,
            'olab', new \Illuminate\Database\Eloquent\Collection, 1);
        $this->assertTrue(\App\Services\Laboratory\GdaOrderConfirmation::valid($result));
        Http::assertNothingSent();
        $this->assertInstanceOf(\App\Services\EfevooPay\MockEfevooPayGateway::class, app(\App\Contracts\EfevooPayGateway::class));
    }

    #[Test]
    public function general_workers_and_scheduler_are_refused_before_consumption(): void
    {
        $argv = $_SERVER['argv'];
        try {
            foreach (['queue:work', 'queue:listen', 'queue:retry', 'schedule:run', 'schedule:work'] as $command) {
                $_SERVER['argv'] = ['artisan', $command];
                try { (new LocalCheckoutQaServiceProvider(app()))->boot(); $this->fail('Historical queue exposed'); }
                catch (\RuntimeException $e) { $this->assertSame('local_qa_use_guarded_worker_or_focal_reconcile', $e->getMessage()); }
            }
        } finally { $_SERVER['argv'] = $argv; }
    }

    #[Test]
    public function queued_notification_is_blocked_when_it_is_executed(): void
    {
        $inner = new ArrayTransport;
        $manager = new LocalQaMailManager(app());
        $manager->extend('smtp', fn () => $inner);
        config(['mail.default' => 'qa-probe', 'mail.mailers.qa-probe' => ['transport' => 'smtp', 'host' => 'smtp.gmail.com']]);
        app()->instance('mail.manager', $manager);
        \Illuminate\Support\Facades\Mail::clearResolvedInstance('mail.manager');
        $notifiable = (new \Illuminate\Notifications\AnonymousNotifiable)->route('mail', 'other@example.test');
        $job = new \Illuminate\Notifications\SendQueuedNotifications($notifiable, new LocalQaProbeNotification, ['mail']);
        $job = unserialize(serialize($job));
        try { $job->handle(app(\Illuminate\Notifications\ChannelManager::class)); $this->fail('Queued notification leak'); }
        catch (\RuntimeException $e) { $this->assertSame('local_qa_recipient_blocked', $e->getMessage()); }
        $this->assertCount(0, $inner->messages());
    }

    #[Test]
    public function custom_mail_url_cannot_choose_another_smtp_host(): void
    {
        $manager = new LocalQaMailManager(app());
        config(['mail.mailers.qa-url' => ['transport' => 'smtp', 'url' => 'smtp://example.test:2525']]);
        $this->expectExceptionMessage('local_qa_transport_blocked');
        $manager->mailer('qa-url');
    }
}


class LocalQaProbeNotification extends \Illuminate\Notifications\Notification implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;
    public function via($notifiable): array { return ['mail']; }
    public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)->subject('Synthetic QA')->line('Synthetic QA');
    }
}
