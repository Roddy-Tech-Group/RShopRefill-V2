<?php

namespace App\Domain\Communication\Enums;

/**
 * Every outgoing communication belongs to a category that determines
 * the sender mailbox, preference gating, and queue priority.
 *
 * Categories are grouped by the business domain they serve. The enum
 * value is stored in communication_logs.category for analytics.
 */
enum CommunicationCategory: string
{
    // ── Verify (verify@) ──────────────────────────────────────────
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case PinReset = 'pin_reset';
    case EmailChange = 'email_change';
    case AccountActivation = 'account_activation';
    case AccountClaim = 'account_claim';

    // ── Security (security@) ──────────────────────────────────────
    case Otp = 'otp';
    case LoginAlert = 'login_alert';
    case NewDevice = 'new_device';
    case TrustedDevice = 'trusted_device';
    case PasswordChanged = 'password_changed';
    case PinChanged = 'pin_changed';
    case PinLocked = 'pin_locked';
    case TwoFactor = 'two_factor';
    case AccountRestriction = 'account_restriction';
    case SuspiciousLogin = 'suspicious_login';
    case DeviceRemoved = 'device_removed';
    case SecurityIncident = 'security_incident';
    case AccountRecovered = 'account_recovered';

    // ── Wallet (wallet@) ──────────────────────────────────────────
    case WalletFunding = 'wallet_funding';
    case WalletWithdrawal = 'wallet_withdrawal';
    case WalletTransfer = 'wallet_transfer';
    case FxConversion = 'fx_conversion';
    case Cashback = 'cashback';
    case RcoinReward = 'rcoin_reward';
    case RcoinWithdrawal = 'rcoin_withdrawal';
    case WalletDebit = 'wallet_debit';

    // ── Orders (orders@) ──────────────────────────────────────────
    case OrderPlaced = 'order_placed';
    case OrderFulfilled = 'order_fulfilled';
    case GiftCard = 'gift_card';
    case Esim = 'esim';
    case EsimLowData = 'esim_low_data';
    case Airtime = 'airtime';
    case BillPayment = 'bill_payment';
    case Receipt = 'receipt';
    case Refund = 'refund';
    case Invoice = 'invoice';
    case TradeUpdate = 'trade_update';

    // ── Transactions (transactions@) ──────────────────────────────
    case IncompleteFunding = 'incomplete_funding';
    case ExpiredPayment = 'expired_payment';
    case CancelledPayment = 'cancelled_payment';
    case FailedCheckout = 'failed_checkout';
    case PaymentRecovery = 'payment_recovery';
    case FundingFailed = 'funding_failed';
    case TransactionFeedback = 'transaction_feedback';

    // ── Notifications (notifications@) ────────────────────────────
    case FeatureAnnouncement = 'feature_announcement';
    case Maintenance = 'maintenance';
    case ProductUpdate = 'product_update';
    case ServiceNotice = 'service_notice';
    case GiftCardsRestored = 'gift_cards_restored';
    case GiftCardsUnavailable = 'gift_cards_unavailable';

    // ── Marketing (hello@) ────────────────────────────────────────
    case Newsletter = 'newsletter';
    case Campaign = 'campaign';
    case Promotion = 'promotion';
    case ReferralProgram = 'referral_program';

    // ── Compliance (compliance@) — reserved ───────────────────────
    case Kyc = 'kyc';
    case RegulatoryUpdate = 'regulatory_update';
    case PrivacyUpdate = 'privacy_update';
    case TermsChange = 'terms_change';

    // ── System / Admin ────────────────────────────────────────────
    case AdminAlert = 'admin_alert';
    case CriticalSystemAlert = 'critical_system_alert';
    case AdminDirectMessage = 'admin_direct_message';

    /**
     * Resolve which MailDomain this category belongs to.
     */
    public function mailDomain(): MailDomain
    {
        return match ($this) {
            // Verify
            self::EmailVerification,
            self::PasswordReset,
            self::PinReset,
            self::EmailChange,
            self::AccountActivation,
            self::AccountClaim => MailDomain::Verify,

            // Security
            self::Otp,
            self::LoginAlert,
            self::NewDevice,
            self::TrustedDevice,
            self::PasswordChanged,
            self::PinChanged,
            self::PinLocked,
            self::TwoFactor,
            self::AccountRestriction,
            self::SuspiciousLogin,
            self::DeviceRemoved,
            self::SecurityIncident,
            self::AccountRecovered => MailDomain::Security,

            // Wallet
            self::WalletFunding,
            self::WalletWithdrawal,
            self::WalletTransfer,
            self::FxConversion,
            self::Cashback,
            self::RcoinReward,
            self::RcoinWithdrawal,
            self::WalletDebit => MailDomain::Wallet,

            // Orders
            self::OrderPlaced,
            self::OrderFulfilled,
            self::GiftCard,
            self::Esim,
            self::EsimLowData,
            self::Airtime,
            self::BillPayment,
            self::Receipt,
            self::Refund,
            self::Invoice,
            self::TradeUpdate => MailDomain::Orders,

            // Transactions
            self::IncompleteFunding,
            self::ExpiredPayment,
            self::CancelledPayment,
            self::FailedCheckout,
            self::PaymentRecovery,
            self::FundingFailed,
            self::TransactionFeedback => MailDomain::Transactions,

            // Notifications
            self::FeatureAnnouncement,
            self::Maintenance,
            self::ProductUpdate,
            self::ServiceNotice,
            self::GiftCardsRestored,
            self::GiftCardsUnavailable => MailDomain::Notifications,

            // Marketing
            self::Newsletter,
            self::Campaign,
            self::Promotion,
            self::ReferralProgram => MailDomain::Marketing,

            // Compliance
            self::Kyc,
            self::RegulatoryUpdate,
            self::PrivacyUpdate,
            self::TermsChange => MailDomain::Compliance,

            // System / Admin
            self::AdminAlert,
            self::CriticalSystemAlert,
            self::AdminDirectMessage => MailDomain::Security,
        };
    }

    /**
     * Whether this category can be disabled by the user.
     * Security, Verify, and Compliance categories are mandatory.
     */
    public function isUserDisableable(): bool
    {
        return ! in_array($this->mailDomain(), [
            MailDomain::Verify,
            MailDomain::Security,
            MailDomain::Compliance,
        ], true);
    }

    /**
     * Map to the preference column group for PreferenceManager lookups.
     */
    public function preferenceGroup(): ?string
    {
        return match ($this->mailDomain()) {
            MailDomain::Wallet => 'wallet',
            MailDomain::Orders => 'orders',
            MailDomain::Transactions => 'transactions',
            MailDomain::Notifications => 'product_updates',
            MailDomain::Marketing => 'marketing',
            // Non-disableable — no preference group
            default => null,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::EmailVerification => 'Email Verification',
            self::PasswordReset => 'Password Reset',
            self::Otp => 'One-Time Password',
            self::NewDevice => 'New Device Detected',
            self::WalletFunding => 'Wallet Funding',
            self::WalletDebit => 'Wallet Debit',
            self::OrderPlaced => 'Order Placed',
            self::OrderFulfilled => 'Order Fulfilled',
            self::Refund => 'Refund Processed',
            self::GiftCardsRestored => 'Gift Cards Restored',
            self::GiftCardsUnavailable => 'Gift Cards Unavailable',
            default => str_replace('_', ' ', ucfirst($this->value)),
        };
    }
}
