<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
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
