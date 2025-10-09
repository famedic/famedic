<?php

namespace Tests\Browser\Pages;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class ResetPassword extends Page
{
    public string $token;

    public User $user;

    public function url(): string
    {
        $this->user = User::factory()->create();

        $this->token = Password::createToken($this->user);

        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $this->user->email,
        ]));
    }

    public function assert(Browser $browser): void
    {
        $browser->assertRouteIs('password.reset', [
            'token' => $this->token,
        ])->assertInputValue('@email', $this->user->email);
    }

    public function resetPassword(Browser $browser, string $password, string $passwordConfirmation): void
    {
        $browser->type('@password', $password)
            ->type('@passwordConfirmation', $passwordConfirmation)
            ->press('@resetPassword');
    }
}
