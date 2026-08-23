<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

uses(
    DuskTestCase::class,
    DatabaseTruncation::class,
)->in('Browser');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function medicalAttentionUser(array $customerAttributes = []): \App\Models\User
{
    $user = \App\Models\User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ]);

    if ($customerAttributes !== []) {
        $user->customer->update($customerAttributes);
    }

    return $user->fresh(['customer']);
}

function threeDsAdminCustomer(): \App\Models\User
{
    return medicalAttentionUser();
}

function threeDsAdmin(array $permissions = ['payment-attempts.manage']): \App\Models\User
{
    $user = \App\Models\User::factory()
        ->withCompleteProfile()
        ->withAdministrator()
        ->create([
            'documentation_accepted_at' => now(),
        ]);

    foreach ($permissions as $permission) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ]);
    }

    $user->administrator->givePermissionTo($permissions);

    return $user->fresh(['administrator.permissions']);
}

function threeDsAdminAttempt(\App\Models\User $customerUser, array $overrides = []): \App\Models\PaymentAuthenticationAttempt
{
    return \App\Models\PaymentAuthenticationAttempt::factory()->create(array_merge([
        'customer_id' => $customerUser->customer->id,
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinute(),
        'status' => \App\Enums\PaymentAuthenticationAttemptStatus::Completed->value,
    ], $overrides));
}
