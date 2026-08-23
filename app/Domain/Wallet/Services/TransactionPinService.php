<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinCreated;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinResetRequested;
use App\Domain\Wallet\Events\TransactionPinVerificationFailed;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionPinService
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    private const WEAK_PINS = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '2345', '3456', '4567', '5678', '6789', '0123',
        '4321', '3210', '9876', '8765', '7654', '6543', '5432',
    ];

    /**
     * Setup a new PIN for a user who does not have one.
     */
    public function setupPin(User $user, string $pin): void
    {
        if ($user->hasTransactionPin()) {
            throw ValidationException::withMessages(['pin' => 'Transaction PIN is already set.']);
        }

        $this->validateStrength($pin);

        $user->transaction_pin = Hash::make($pin);
        $user->transaction_pin_set_at = now();
        $user->pin_status = 'active';
        $user->save();

        event(new TransactionPinCreated($user));
    }

    /**
     * Verify a PIN and return an AuthorizationToken (string) if valid.
     */
    public function verifyPin(User $user, string $pin): string
    {
        if (! $user->hasTransactionPin()) {
            throw ValidationException::withMessages(['pin' => 'Transaction PIN is not set.']);
        }

        if ($user->isTransactionPinLocked()) {
            throw ValidationException::withMessages(['pin' => 'Too many failed attempts. Try again later.']);
        }

        if (! $user->verifyTransactionPin($pin)) {
            $user->incrementTransactionPinAttempts();

            if ($user->transaction_pin_attempts >= self::MAX_ATTEMPTS) {
                $user->lockTransactionPin(self::LOCKOUT_MINUTES);
                event(new TransactionPinLocked($user));
                throw ValidationException::withMessages(['pin' => 'Too many failed attempts. PIN locked for 15 minutes.']);
            }

            event(new TransactionPinVerificationFailed($user));
            throw ValidationException::withMessages(['pin' => 'Invalid transaction PIN.']);
        }

        $user->resetTransactionPinAttempts();
        $user->markTransactionPinUsed();

        // Generate short-lived auth token (valid for 5 minutes)
        $token = 'auth_pin_'.Str::random(40);
        Cache::put("pin_auth_{$user->id}_{$token}", true, now()->addMinutes(5));

        return $token;
    }

    /**
     * Check if a given authorization token is valid for the user.
     */
    public function validateAuthToken(User $user, string $token): bool
    {
        return Cache::has("pin_auth_{$user->id}_{$token}");
    }

    /**
     * Atomically claim (validate + consume) an authorization token: exactly
     * one caller can ever win a given token. The old validate-then-forget
     * pair had a race where two concurrent payments both passed validation
     * before either consumed, double-authorizing one PIN entry; Cache::add is
     * a set-if-absent, so of two simultaneous claims exactly one takes the
     * claimed marker and the other is refused.
     */
    public function claimAuthToken(User $user, string $token): bool
    {
        if (! Cache::has("pin_auth_{$user->id}_{$token}")) {
            return false;
        }

        if (! Cache::add("pin_auth_claimed_{$user->id}_{$token}", true, now()->addMinutes(10))) {
            return false;
        }

        Cache::forget("pin_auth_{$user->id}_{$token}");

        return true;
    }

    /**
     * Change PIN with old PIN verification.
     */
    public function changePin(User $user, string $oldPin, string $newPin): void
    {
        // verifyPin will throw if oldPin is invalid or locked
        $this->verifyPin($user, $oldPin);

        $this->validateStrength($newPin);

        $user->transaction_pin = Hash::make($newPin);
        $user->transaction_pin_set_at = now();
        $user->save();

        event(new TransactionPinChanged($user));
    }

    /**
     * Request a PIN reset (generates secure token and triggers email).
     */
    public function requestReset(User $user): void
    {
        $token = Str::random(60);

        Cache::put("pin_reset_{$user->id}", $token, now()->addHours(1));

        event(new TransactionPinResetRequested($user, $token));
    }

    /**
     * Confirm a PIN reset using the token.
     */
    public function confirmReset(User $user, string $token, string $newPin): void
    {
        $cachedToken = Cache::get("pin_reset_{$user->id}");

        if (! $cachedToken || ! hash_equals($cachedToken, $token)) {
            throw ValidationException::withMessages(['token' => 'Invalid or expired reset token.']);
        }

        $this->validateStrength($newPin);

        $user->transaction_pin = Hash::make($newPin);
        $user->transaction_pin_set_at = now();
        $user->pin_status = 'active';
        $user->resetTransactionPinAttempts(); // Reset any lockouts
        $user->save();

        Cache::forget("pin_reset_{$user->id}");

        event(new TransactionPinChanged($user));
    }

    /**
     * Remove PIN completely (requires password).
     */
    public function removePin(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Invalid password.']);
        }

        $user->transaction_pin = null;
        $user->transaction_pin_set_at = null;
        $user->transaction_pin_attempts = 0;
        $user->transaction_pin_locked_until = null;
        $user->pin_status = 'active';
        $user->last_transaction_pin_used_at = null;
        $user->save();

        // You could dispatch a TransactionPinRemoved event here if desired.
    }

    /**
     * Admin-initiated reset: clears the customer's PIN entirely without needing
     * their password or current PIN. The customer is prompted to set a fresh PIN
     * before their next wallet action. Use only from authorised admin flows.
     */
    public function adminReset(User $user): void
    {
        $user->transaction_pin = null;
        $user->transaction_pin_set_at = null;
        $user->transaction_pin_attempts = 0;
        $user->transaction_pin_locked_until = null;
        $user->pin_status = 'reset_required';
        $user->last_transaction_pin_used_at = null;
        $user->save();

        event(new TransactionPinChanged($user));
    }

    /**
     * Validate PIN strength rules.
     */
    public function validateStrength(string $pin): void
    {
        if (! preg_match('/^[0-9]{4}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN must be exactly 4 digits.']);
        }

        if (in_array($pin, self::WEAK_PINS, true)) {
            throw ValidationException::withMessages(['pin' => 'This PIN is too weak or easy to guess.']);
        }
    }
}
