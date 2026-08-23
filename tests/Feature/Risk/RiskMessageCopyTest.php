<?php

namespace Tests\Feature\Risk;

use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Models\RiskReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer-facing copy per outcome, and its context-aware noun: "purchase"
 * on an order, "request" on a wallet funding. The decline message stays neutral.
 */
class RiskMessageCopyTest extends TestCase
{
    use RefreshDatabase;

    private function stepUp(string $context): KycStepUpRequiredException
    {
        return new KycStepUpRequiredException(
            KycTier::IdentityVerified,
            new KycSession('ref'),
            User::factory()->make(),
            $context,
        );
    }

    public function test_step_up_copy_is_context_aware(): void
    {
        $this->assertSame(
            'To continue with this purchase, please complete a quick identity verification.',
            $this->stepUp('order')->customerMessage(),
        );
        $this->assertSame(
            'To continue with this request, please complete a quick identity verification.',
            $this->stepUp('funding')->customerMessage(),
        );
    }

    public function test_review_copy_is_context_aware(): void
    {
        $order = new RiskReviewRequiredException(RiskReview::factory()->make(['context' => 'order']));
        $funding = new RiskReviewRequiredException(RiskReview::factory()->make(['context' => 'funding']));

        $this->assertSame('Your purchase is being reviewed by our team and will be processed shortly.', $order->customerMessage());
        $this->assertSame('Your request is being reviewed by our team and will be processed shortly.', $funding->customerMessage());
    }

    public function test_decline_copy_is_neutral(): void
    {
        $this->assertSame(
            'We could not process this transaction for security reasons. If you believe this is a mistake, please contact support.',
            (new RiskDeniedException)->customerMessage(),
        );
    }
}
