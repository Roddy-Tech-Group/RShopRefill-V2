<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin email OTP is six digits and lives ten minutes; with no wrong-guess
 * cap it was guessable online. Five wrong codes now end the challenge: the
 * code is burned and the admin must sign in again for a fresh one.
 */
class AdminTwoFactorGuessCapTest extends TestCase
{
    use RefreshDatabase;

    private function adminMidChallenge(): Admin
    {
        $admin = Admin::create([
            'name' => 'Ops Admin',
            'email' => 'ops@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $admin->forceFill([
            'email_otp' => '135790',
            'email_otp_expires_at' => now()->addMinutes(10),
        ])->save();

        return $admin;
    }

    public function test_five_wrong_codes_burn_the_code_and_end_the_challenge(): void
    {
        $admin = $this->adminMidChallenge();

        foreach (range(1, 5) as $attempt) {
            $this->withSession(['admin_2fa_id' => $admin->id])
                ->from(route('admin.2fa.challenge'))
                ->post(route('admin.2fa.verify'), ['code' => '000000'])
                ->assertRedirect(route('admin.2fa.challenge'))
                ->assertSessionHasErrors(['code']);

            $this->assertSame('135790', $admin->fresh()->email_otp, "the code must survive wrong guess {$attempt}");
        }

        $this->withSession(['admin_2fa_id' => $admin->id])
            ->from(route('admin.2fa.challenge'))
            ->post(route('admin.2fa.verify'), ['code' => '135790'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors(['email']);

        $this->assertNull($admin->fresh()->email_otp, 'the sixth attempt must burn the code, right or wrong');
        $this->assertGuest('admin');
    }

    public function test_the_right_code_within_the_budget_signs_the_admin_in(): void
    {
        $admin = $this->adminMidChallenge();

        $this->withSession(['admin_2fa_id' => $admin->id])
            ->from(route('admin.2fa.challenge'))
            ->post(route('admin.2fa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors(['code']);

        $this->withSession(['admin_2fa_id' => $admin->id])
            ->post(route('admin.2fa.verify'), ['code' => '135790'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNull($admin->fresh()->email_otp);
    }
}
