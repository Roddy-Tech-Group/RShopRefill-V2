<?php

namespace Tests\Unit;

use App\Rules\HardToGuessPassword;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The part of the password policy that refuses what is guessed first: patterns,
 * common passwords, and the account's own name or email. The mobile app checks
 * the same list as the customer types (src/lib/auth/password.ts).
 */
class HardToGuessPasswordTest extends TestCase
{
    /** @param array<string, string> $context */
    private function message(string $password, array $context = []): ?string
    {
        $validator = Validator::make(['password' => $password] + $context, [
            'password' => [new HardToGuessPassword],
        ]);

        return $validator->fails() ? $validator->errors()->first('password') : null;
    }

    public function test_patterns_are_refused(): void
    {
        foreach (['aaaaaaaa', '11111111', 'abababab', '12341234', 'ab12ab12', '12345678', '87654321',
            'abcdefgh', 'qwertyui', 'iuytrewq', 'asdfghjk', 'zxcvbnm1', '1qaz2wsx3edc'] as $password) {
            $this->assertSame(HardToGuessPassword::GUESSABLE, $this->message($password), $password);
        }
    }

    public function test_common_passwords_are_refused_with_or_without_a_number_on_the_end(): void
    {
        foreach (['password', 'Password1', 'password123!', 'iloveyou2', 'Welcome2026', 'rshoprefill1', '!football!'] as $password) {
            $this->assertSame(HardToGuessPassword::GUESSABLE, $this->message($password), $password);
        }
    }

    public function test_the_accounts_own_name_or_email_is_refused(): void
    {
        $context = ['email' => 'amina.bello@example.com', 'first_name' => 'Amina', 'last_name' => 'Bello'];

        foreach (['Amina2026!', 'bello-house-77', 'amina.bello', 'my-amina.bello-pass'] as $password) {
            $this->assertSame(HardToGuessPassword::PERSONAL, $this->message($password, $context), $password);
        }
    }

    public function test_ordinary_passwords_pass(): void
    {
        $context = ['email' => 'amina.bello@example.com', 'name' => 'Amina Bello'];

        foreach (['Fresh-start-for-Ada-2026', 'correct horse battery staple', 'Lagos-rain-at-7', 'mango tea 1987',
            'a-long-password', 'kettle99'] as $password) {
            $this->assertNull($this->message($password, $context), $password);
        }
    }

    public function test_short_name_parts_are_not_held_against_a_password(): void
    {
        // "Ada" or "Li" turn up inside ordinary words; only four letters or more count.
        $this->assertNull($this->message('Canada-geese-fly', ['name' => 'Ada Li', 'email' => 'li@example.com']));
    }
}
