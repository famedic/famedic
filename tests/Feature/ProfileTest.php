<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/user');

    $response->assertOk();
});

test('basic info can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/basic-info', [
            'name' => 'Test User',
            'paternal_lastname' => 'Paternal Lastname',
            'maternal_lastname' => 'Maternal Lastname',
            'birth_date' => '2012-12-29',
            'gender' => 1,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/user');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('Paternal Lastname', $user->paternal_lastname);
    $this->assertSame('Maternal Lastname', $user->maternal_lastname);
    $this->assertSame('2012-12-29', $user->birth_date_string);
});

test('contact info can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/contact-info', [
            'email' => 'new@email.com',
            'phone' => '987654321',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/user');

    $user->refresh();

    $this->assertSame('new@email.com', $user->email);
    $this->assertSame('987654321', $user->phone);
    $this->assertNull($user->email_verified_at);
    $this->assertNull($user->phone_verified_at);
});

test('email and phone verification status is unchanged when unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/contact-info', [
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/user');

    $user->refresh();

    $this->assertNotNull($user->email_verified_at);
    $this->assertNotNull($user->phone_verified_at);
});
