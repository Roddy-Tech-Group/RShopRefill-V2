<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Compliance\IdentityNotVerifiedMail;
use App\Domain\Communication\Mail\Compliance\IdentityVerifiedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;

/**
 * Tells the customer how their identity verification ended: in-app and by
 * email from the compliance mailbox. The single place every verdict source
 * (the provider's callback, an admin reconciliation, the already-enrolled
 * read-back, an admin status change) goes through, so the wording never drifts.
 *
 * CommunicationManager::send() never throws, so a delivery hiccup can never
 * undo a verdict that has already been recorded.
 */
class KycOutcomeNotifier
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    /**
     * @param  string  $correlation  identifies the business event, and keys the
     *                               idempotency so a replayed verdict is not
     *                               announced twice
     */
    public function verified(User $user, string $correlation): void
    {
        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::Kyc,
            title: 'You\'re verified! 🎉',
            message: 'Congratulations, your account is now verified. Enjoy seamless shopping!',
            mailable: new IdentityVerifiedMail($user->name),
            correlationId: 'kyc_verified_'.$correlation,
            idempotencyKey: 'kyc_verified_'.$correlation,
        );
    }

    public function notVerified(User $user, string $correlation): void
    {
        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::Kyc,
            title: 'Identity verification needs attention',
            message: 'We could not verify your identity. Please check your ID and selfie, then try again.',
            mailable: new IdentityNotVerifiedMail($user->name),
            correlationId: 'kyc_not_verified_'.$correlation,
            idempotencyKey: 'kyc_not_verified_'.$correlation,
        );
    }
}
