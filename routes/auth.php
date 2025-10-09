<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\BasicInfoUpdateController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\ContactInfoUpdateController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordlessAuthenticationController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PhoneVerificationNotificationController;
use App\Http\Controllers\Auth\PhoneVerificationPromptController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\VerifyPhoneController;
use App\Http\Controllers\CompleteProfileController;
use Illuminate\Support\Facades\Route;

Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
    ->name('password.request');

Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->name('password.email');

Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
    ->name('password.reset');

Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->name('password.store');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::get('register/invitation/{user}', [RegisteredUserController::class, 'createFromInvitation'])
        ->middleware('signed')
        ->name('register.invitation');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/magic-login/{user}', PasswordlessAuthenticationController::class)
        ->name('passwordless.authenticate');
});

Route::middleware('auth')->group(function () {
    Route::middleware(['redirect-complete-user'])->group(function () {
        Route::get('/complete-profile', [CompleteProfileController::class, 'index'])
            ->name('complete-profile');

        Route::post('/complete-profile', [CompleteProfileController::class, 'store'])
            ->name('complete-profile.store');
    });

    Route::get('/user', UserController::class)
        ->name('user.edit');

    Route::put('/basic-info', BasicInfoUpdateController::class)
        ->name('basic-info.update');

    Route::put('/contact-info', ContactInfoUpdateController::class)
        ->name('contact-info.update');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::middleware(['redirect-if-phone-verified'])->group(function () {
        Route::get('verify-phone', PhoneVerificationPromptController::class)
            ->name('phone.verification.notice');

        Route::post('phone/verification-notification', PhoneVerificationNotificationController::class)
            ->middleware('throttle:6,1')
            ->name('phone.verification.send');

        Route::get('verify-phone/confirmation', [VerifyPhoneController::class, 'index'])
            ->name('phone.verification');

        Route::post('verify-phone/confirmation', [VerifyPhoneController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('phone.verification.confirm');
    });

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

Route::get('/auth/redirect', [GoogleAuthController::class, 'redirectToGoogle'])
    ->name('google.auth.redirect');

Route::get('/auth/callback', [GoogleAuthController::class, 'handleGoogleCallback'])
    ->name('google.auth.callback');
