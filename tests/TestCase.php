<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            'password.confirm',
            \App\Http\Middleware\ExcludePasswordConfirm::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    /**
     * Clear sticky auth guards and bind a new Sanctum Bearer for the next HTTP call.
     *
     * Needed when a single Pest/PHPUnit case issues requests as different API users;
     * without this, Auth may keep resolving the first token's user.
     *
     * @return array{Authorization: string}
     */
    public function switchApiBearerToken(string $token): array
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers);

        return $headers;
    }
}
