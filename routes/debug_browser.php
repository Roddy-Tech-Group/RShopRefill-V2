<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/debug-login-browser', function () {
    $user = User::first();
    if (! $user) {
        $user = User::factory()->create();
    }
    Auth::login($user);

    return redirect()->route('dashboard.gift-cards.history');
});
