<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_registration_creates_an_admin_notification(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $this->assertDatabaseHas('admin_notifications', ['type' => 'customer']);
    }
}
