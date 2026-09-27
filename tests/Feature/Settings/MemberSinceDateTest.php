<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The profile header shows the exact day an account was created, not just the
 * month and year.
 */
class MemberSinceDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_since_shows_the_full_date(): void
    {
        $user = User::factory()->create(['created_at' => '2026-06-03 10:00:00']);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->assertSee('Member since 3 June 2026')
            ->assertDontSee('Member since June 2026');
    }
}
