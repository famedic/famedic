<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Nginx (y Cloudflare Tunnel) van delante de PHP; hay que confiar en
        // X-Forwarded-Proto o Laravel genera http:// y el navegador bloquea el JS.
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $at = $trustedProxies === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxies))));
        $middleware->trustProxies(
            at: $at === [] ? '*' : $at,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->validateCsrfTokens(except: [
            'paypal/webhook',
            'apigda/*',
        ]);

        $middleware->web(
            prepend: [
                \App\Http\Middleware\UseForwardedRootUrl::class,
            ],
            append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            ]
        )->alias([
            'admin' => \App\Http\Middleware\EnsureUserHasAdminAccount::class,
            'super.admin' => \App\Http\Middleware\EnsureUserHasSuperAdminRole::class,
            'customer' => \App\Http\Middleware\EnsureUserHasCustomerAccount::class,
            'laboratory-appointment' => \App\Http\Middleware\EnsureValidLaboratoryAppointment::class,
            'no-duplicate-laboratory-appointment' => \App\Http\Middleware\EnsureNoDuplicateLaboratoryAppointment::class,
            'redirect-complete-user' => \App\Http\Middleware\RedirectIfUserProfileIsComplete::class,
            'redirect-incomplete-user' => \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
            'redirect-if-empty-laboratory-cart-items' => \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
            'redirect-if-empty-online-pharmacy-cart-items' => \App\Http\Middleware\RedirectIfEmptyOnlinePharmacyCartItems::class,
            'redirect-if-appointment-confirmed' => \App\Http\Middleware\RedirectIfAppointmentConfirmed::class,
            'phone-verified' => \App\Http\Middleware\EnsurePhoneIsVerified::class,
            'redirect-if-phone-verified' => \App\Http\Middleware\RedirectIfPhoneVerified::class,
            'medical-attention-subscription' => \App\Http\Middleware\RedirectIfMissingMedicalAttentionSubscription::class,
            'documentation' => \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            // 'password.confirm' => \App\Http\Middleware\BypassPasswordConfirm::class,
            'password.confirm' => \App\Http\Middleware\ExcludePasswordConfirm::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'bypass.password.confirm' => \App\Http\Middleware\BypassPasswordConfirm::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return redirect()->route('home');
            }

            return $response;
        });
    })->create();
