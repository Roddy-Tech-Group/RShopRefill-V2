<?php

namespace App\Domain\PaymentInsights\Enums;

enum IneligibilityReason: string
{
    case GuestNoAccount = 'guest_no_account';
    case Suppressed = 'suppressed';
    case FeatureDisabled = 'feature_disabled';
    case UserOptedOut = 'user_opted_out';
    case CooldownActive = 'cooldown_active';
    case MaxAttemptsReached = 'max_attempts_reached';
    case NotApplicable = 'not_applicable';
    case InternalWalletPayment = 'internal_wallet_payment';
    case NonRetryableFailure = 'non_retryable_failure';

    /** The failure was ours. We alert on it and a human writes; the robot stays quiet. */
    case PlatformFault = 'platform_fault';

    /** The gateway never answered, so the customer may have paid. Never invite a second payment. */
    case OutcomeUncertain = 'outcome_uncertain';

    /** Several different cards refused in a short window. Robots do not invite card testers back. */
    case SuspectedTesting = 'suspected_testing';
}
