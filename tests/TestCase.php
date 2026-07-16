<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Switch the HTTP test client to another Akubica Sanctum Bearer token.
     *
     * Consecutive $this->*Json() calls in the same Feature test can keep the
     * previous Auth guard user sticky. Call this before issuing a request as a
     * different API customer so Authorization is applied to the intended token.
     *
     * @return array{Authorization: string}
     */
    public function switchApiBearerToken(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers);

        return $headers;
    }
}
