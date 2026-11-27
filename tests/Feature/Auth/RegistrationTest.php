<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'paternal_lastname' => 'Test paternal',
        'maternal_lastname' => 'Test maternal',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'email' => 'test@example.com',
        // INT-031 (RC13): RegisterRequest requires a valid phone and phone_country (size:2).
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});
