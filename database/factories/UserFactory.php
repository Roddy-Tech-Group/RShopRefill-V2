<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Factory users are fully registered by default, the same state the
            // register wizard leaves a real signup in. Without this every test
            // user would owe the completion step and the guard would redirect
            // them away from the page under test.
            'profile_completed_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A fresh Google sign-up: authenticated, email verified by Google, but still
     * owing the country / phone / gender / terms the register wizard collects.
     */
    public function profileIncomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_completed_at' => null,
            'country' => null,
            'phone' => null,
            'gender' => null,
        ]);
    }
}
