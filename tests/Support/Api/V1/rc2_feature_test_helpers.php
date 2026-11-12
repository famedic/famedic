<?php

if (! function_exists('akubicaCustomerToken')) {
    function akubicaCustomerToken(?\App\Models\User $user = null): array
    {
        $user ??= \App\Models\User::factory()->withRegularCustomer()->create();
        $issued = \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::issue($user);
        return [$user, $issued['token']->plainTextToken];
    }
}

if (! function_exists('authHeaders')) {
    function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token]
            + \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::headers($token);
    }
}
