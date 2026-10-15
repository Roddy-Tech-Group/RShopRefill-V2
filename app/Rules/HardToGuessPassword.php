<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A password nobody could guess from a list or from the account itself: the
 * part of the password policy (Password::defaults, AppServiceProvider) beyond
 * its length and the breached-password check. NIST-style, as the policy is: no
 * composition rules, only refusing what is guessed first.
 *
 * - A pattern: one to four characters repeated (aaaaaaaa, abababab, 12341234),
 *   or a run along the digits, the alphabet or a keyboard row (12345678,
 *   abcdefgh, qwertyui), either way.
 * - A common password with a number or symbol on the end (password1,
 *   iloveyou!), and the shop's own name.
 * - The account's own name or email address.
 *
 * The account is read from the form (email, first_name, last_name, name), the
 * account handed in, or whoever is signed in. The mobile app checks the same
 * things as the customer types (rshoprefill-mobileapp, src/lib/auth/password.ts),
 * with the same messages.
 */
final class HardToGuessPassword implements DataAwareRule, ValidationRule
{
    public const GUESSABLE = 'This password is too easy to guess. Avoid common words and patterns like aaaa or 1234.';

    public const PERSONAL = 'Your password must not contain your name or email address.';

    /** Runs a password must not sit inside, either way along. */
    private const SEQUENCES = [
        '01234567890123456789',
        'abcdefghijklmnopqrstuvwxyz',
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
        '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p',
    ];

    /** The passwords tried first, and the shop's own name. */
    private const COMMON = [
        'password', 'passw0rd', 'iloveyou', 'letmein', 'welcome', 'qwerty', 'admin', 'login',
        'football', 'baseball', 'monkey', 'dragon', 'sunshine', 'princess', 'shadow', 'master',
        'trustno1', 'superman', 'batman', 'starwars', 'whatever', 'freedom', 'secret', 'jesus',
        'blessing', 'rshoprefill', 'rshop', 'refill',
    ];

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly ?User $user = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $password = Str::lower($value);

        if ($this->isPattern($password) || $this->isCommon($password)) {
            $fail(self::GUESSABLE);

            return;
        }

        foreach ($this->personalWords() as $word) {
            if (str_contains($password, $word)) {
                $fail(self::PERSONAL);

                return;
            }
        }
    }

    private function isPattern(string $password): bool
    {
        // One to four characters, repeated to the end.
        if (preg_match('/^(.{1,4})\1+.{0,3}$/su', $password) === 1) {
            return true;
        }

        foreach ($this->cores($password) as $core) {
            foreach (self::SEQUENCES as $sequence) {
                if (str_contains($sequence, $core) || str_contains(strrev($sequence), $core)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The password, and the password without up to three characters added to
     * the end or the start of a run (zxcvbnm1, abcdef!!, !12345678), when six
     * or more are left.
     *
     * @return list<string>
     */
    private function cores(string $password): array
    {
        $cores = [
            $password,
            preg_replace('/[^a-z0-9]{1,3}$/', '', $password),
            preg_replace('/[^a-z]{1,3}$/', '', $password),
            preg_replace('/^[^a-z0-9]{1,3}/', '', $password),
            preg_replace('/^[^a-z]{1,3}/', '', $password),
        ];

        return array_values(array_unique(array_filter(
            $cores,
            static fn (?string $core): bool => $core !== null && strlen($core) >= 6,
        )));
    }

    /** A common password, give or take a number or symbol on either end. */
    private function isCommon(string $password): bool
    {
        $word = preg_replace('/^[^a-z]+|[^a-z]+$/', '', $password) ?? $password;

        return in_array($word, self::COMMON, true);
    }

    /**
     * The account's name parts and the part of its email before the @, of four
     * letters or more (shorter ones turn up inside ordinary words).
     *
     * @return list<string>
     */
    private function personalWords(): array
    {
        $account = $this->user ?? (auth()->user() instanceof User ? auth()->user() : null);

        $sources = [
            $this->data['email'] ?? null,
            $this->data['first_name'] ?? null,
            $this->data['last_name'] ?? null,
            $this->data['name'] ?? null,
            $account?->email,
            $account?->name,
        ];

        $words = [];

        foreach ($sources as $source) {
            if (! is_string($source) || $source === '') {
                continue;
            }

            $text = Str::lower(Str::before($source, '@'));

            foreach (preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [] as $part) {
                if (mb_strlen($part) >= 4) {
                    $words[] = $part;
                }
            }

            // The whole local part too ("amina.bello" as typed, not only its halves).
            if (mb_strlen($text) >= 4 && str_contains($source, '@')) {
                $words[] = $text;
            }
        }

        return array_values(array_unique($words));
    }
}
