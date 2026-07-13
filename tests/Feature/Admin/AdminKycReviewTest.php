<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminKycReviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    private function submissionFor(User $user): KycSubmission
    {
        return KycSubmission::create([
            'user_id' => $user->id,
            'full_name' => 'Jane Doe',
            'date_of_birth' => '1995-04-12',
            'country' => 'Nigeria',
            'document_type' => 'passport',
            'document_number' => 'A1234567',
            'document_front_path' => "kyc/{$user->id}/front.jpg",
            'selfie_path' => "kyc/{$user->id}/selfie.jpg",
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_approve_a_submission(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $submission = $this->submissionFor($user);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.approve', $submission))
            ->assertRedirect();

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_admin_can_reject_with_a_reason(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $submission = $this->submissionFor($user);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.reject', $submission), ['reason' => 'Blurry photo'])
            ->assertRedirect();

        $fresh = $submission->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Blurry photo', $fresh->rejection_reason);
        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $submission = $this->submissionFor($user);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.reject', $submission), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $submission->fresh()->status);
    }

    public function test_documents_are_admin_only(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $submission = $this->submissionFor($user);
        Storage::disk('local')->put($submission->document_front_path, 'fake-bytes');

        // Guests are bounced to the admin login.
        $this->get(route('admin.kyc.document', [$submission, 'front']))
            ->assertNotFound();

        // Admins can stream the document.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.kyc.document', [$submission, 'front']))
            ->assertOk();
    }

    public function test_unknown_document_type_is_not_found(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $submission = $this->submissionFor($user);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.kyc.document', [$submission, 'passport']))
            ->assertNotFound();
    }
}
