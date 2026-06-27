<?php
// app/Http/Middleware/VerifyCsrfToken.php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'paypal/webhook',
        'apigda/*',
    ];

    public function handle($request, \Closure $next)
    {
        if ($this->app->environment('testing') || $this->app->runningUnitTests() || defined('PHPUNIT_COMPOSER_INSTALL')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}