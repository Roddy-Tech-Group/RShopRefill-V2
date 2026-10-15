<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    // Real pages, not a modal. These used to bounce to the home page with an
    // ?auth= query param so the global auth modal could pop open over it; the
    // sign-in and create-account screens are now full pages under the
    // storefront nav, entering from the left and the right respectively.
    //
    // Bookmarks and email links pointing at /login and /register therefore land
    // on the page itself rather than on the home page - which is what those
    // URLs always claimed to be.
    Volt::route('login', 'auth.login')->name('login');
    Volt::route('register', 'auth.register')->name('register');

    Volt::route('forgot-password', 'auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'auth.reset-password')
        ->name('password.reset');

    // Google OAuth
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect');
});

// The mobile app's Google sign-in, and the callback every Google sign-in
// returns to. Not guest-only: the app opens these in the phone's browser,
// which on Android shares Chrome's cookies and may already be signed in to the
// website, and that must not turn the app's sign-in into a trip to the
// dashboard. A website sign-in can still only begin signed out (the redirect
// above), and the OAuth state check guards the callback either way.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('auth/google/app', [GoogleAuthController::class, 'app'])
        ->name('auth.google.app');

    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');
});

// The verification link works on ANY device, signed in or not. The signed
// URL and the email hash are the proof; requiring a session here bounced the
// phone that opened the email to the login page and made it the account's
// "new device". See VerifyEmailController. It never signs anyone in.
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'auth.verify-email')
        ->name('verification.notice');

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
