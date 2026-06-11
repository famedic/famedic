<?php

use App\Models\User;

test('GET /api/v1/cart without token returns 401 JSON', function () {
    $response = $this->getJson('/api/v1/cart?brand=olab');

    $response->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
});

test('unknown /api/v1 route returns 404 JSON', function () {
    $response = $this->getJson('/api/v1/ruta-inexistente');

    $response->assertNotFound()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'NOT_FOUND',
            ],
        ]);
});

test('POST /api/v1/auth/login/request-code returns verification response for valid email', function () {
    Notification::fake();

    User::factory()->create(['email' => 'paciente@ejemplo.com']);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'paciente@ejemplo.com',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'verification_sent' => true,
                'channel' => 'email',
            ],
        ]);
});

test('POST /api/v1/auth/login/request-code validates email', function () {
    $response = $this->postJson('/api/v1/auth/login/request-code', []);

    $response->assertUnprocessable()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Los datos enviados no son válidos.',
            ],
        ])
        ->assertJsonStructure([
            'error' => ['fields' => ['email']],
        ]);
});

test('protected endpoint with invalid token returns 401 JSON', function () {
    $response = $this->getJson('/api/v1/cart?brand=olab', [
        'Authorization' => 'Bearer token-invalido',
    ]);

    $response->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
});

test('DELETE /api/v1/auth/token revokes current token', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $response = $this->deleteJson('/api/v1/auth/token', [], [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'revoked' => true,
            ],
        ]);

    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/cart?brand=olab', [
        'Authorization' => 'Bearer '.$token,
    ])->assertUnauthorized();
});

test('authenticated user without customer receives 403 on protected routes', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $response = $this->getJson('/api/v1/cart?brand=olab', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertForbidden()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'El usuario no tiene perfil de cliente asociado.',
            ],
        ]);
});
