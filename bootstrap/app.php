<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ])->alias([
            'admin' => \App\Http\Middleware\EnsureUserHasAdminAccount::class,
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
