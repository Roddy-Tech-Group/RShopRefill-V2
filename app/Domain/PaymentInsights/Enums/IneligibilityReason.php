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
}
