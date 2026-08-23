<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Models\Admin;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin risk-review queue: list, and the approve/reject/escalate actions,
 * each admin-only and audit-logged.
 */
class AdminRiskReviewQueueTest extends TestCase
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

    public function test_the_queue_requires_an_admin(): void
    {
        RiskReview::factory()->create();

        // AdminAuth hides the panel from guests with a 404 rather than a redirect.
        $this->get(route('admin.risk.reviews'))->assertNotFound();
    }

    public function test_an_admin_sees_pending_reviews(): void
    {
        $review = RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.risk.reviews'))
            ->assertOk()
            ->assertSee($review->user->email);
    }

    public function test_an_admin_can_approve_a_review(): void
    {
        $review = RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.risk.review.approve', $review), ['reason' => 'Looks fine'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(RiskReviewStatus::Approved, $review->status);
        $this->assertTrue($review->hasLiveClearance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.approved']);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $review = RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.risk.review.reject', $review), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(RiskReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_an_admin_can_reject_with_a_reason(): void
    {
        $review = RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.risk.review.reject', $review), ['reason' => 'Card testing'])
            ->assertRedirect();

        $this->assertSame(RiskReviewStatus::Rejected, $review->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.rejected']);
    }

    public function test_an_admin_can_escalate(): void
    {
        $review = RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.risk.review.escalate', $review), ['reason' => 'Second look'])
            ->assertRedirect();

        $this->assertSame(RiskReviewStatus::Escalated, $review->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.escalated']);
    }

    public function test_the_metrics_dashboard_renders(): void
    {
        RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.risk.dashboard'))
            ->assertOk()
            ->assertSee('Risk Engine')
            ->assertSee('Review queue');
    }

    // The engine handbook moved to the Management runbooks library
    // (admin.management.runbooks.doc); its coverage lives in
    // ManagementPagesTest alongside the rest of that section.

    public function test_an_admin_can_record_a_label(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.risk.label'), [
                'user_id' => $user->id,
                'label' => RiskLabel::LABEL_CHARGEBACK,
                'note' => 'Disputed via bank',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('risk_labels', [
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CHARGEBACK,
            'source' => 'admin',
        ]);
    }
}
