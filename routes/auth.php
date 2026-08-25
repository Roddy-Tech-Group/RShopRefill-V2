<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    // Direct hits to /login and /register bounce to the home page with an
    // ?auth= query param. The global <livewire:auth.auth-modal> mounted in the
    // storefront layout sees the param on boot and pops the modal open over
    // the home page so the user gets the new slide-up modal experience even
    // when they land on the legacy auth URLs (bookmarks, email links, etc.).
    Route::get('login', fn () => redirect()->to('/?auth=login'))->name('login');
    Route::get('register', fn () => redirect()->to('/?auth=register'))->name('register');

    Volt::route('forgot-password', 'auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'auth.reset-password')
        ->name('password.reset');

    // Google OAuth
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect');

    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // On-demand resend (checkout notice's Resend button). Escalating cooldown +
    // the flat budget are both enforced inside; this throttle is just the
    // request-level ceiling.
    Route::post('email/verification-notification', ResendVerificationController::class)
        ->middleware('throttle:12,1')
        ->name('verification.send');

    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');

    // Finishes a Google sign-up. Google returns no country, phone or gender, so
    // a brand-new Google account is held here by EnsureProfileCompleted until it
    // supplies them and accepts the terms. Every other account passes straight
    // through and never sees this page.
    Volt::route('auth/complete-profile', 'auth.complete-profile')
        ->name('auth.complete-profile');
});

Route::post('logout', Logout::class)
    ->name('logout');

// OAuth popup completion page. The Google sign-in popup lands here after a
// successful (or failed) callback; tiny JS notifies the opener window and
// closes itself. Accessible to anyone because the user is logged in by the
// time they reach this page (or still a guest if the OAuth flow failed).
Route::view('auth/popup-complete', 'auth.popup-complete')
    ->name('auth.popup-complete');
