<?php

namespace App\Domain\Communication\Enums;

use App\Models\SiteSetting;

/**
 * Sender mailbox domains. Each maps to a verified Resend sender
 * address on the rshoprefill.com domain.
 *
 * All outgoing emails carry Reply-To: support@rshoprefill.com.
 */
enum MailDomain: string
{
    case Verify = 'verify';
    case Security = 'security';
    case Wallet = 'wallet';
    case Orders = 'orders';
    case Transactions = 'transactions';
    case Notifications = 'notifications';
    case Marketing = 'marketing';
    case Compliance = 'compliance';

    /**
     * The full sender email address for this mailbox.
     */
    public function senderAddress(): string
    {
        return $this->value.'@rshoprefill.com';
    }

    /**
     * The display name used in the "From" header.
     *
     * Sourced from SiteSetting at runtime so admin panel changes
     * apply without restarting queue workers.
     */
    public function senderName(): string
    {
        $brand = SiteSetting::get('site.name', 'RshopRefill');

        return match ($this) {
            self::Verify => $brand.' Verification',
            self::Security => $brand.' Security',
            self::Wallet => $brand.' Wallet',
            self::Orders => $brand.' Orders',
            self::Transactions => $brand.' Transactions',
            self::Notifications => $brand,
            self::Marketing => $brand,
            self::Compliance => $brand.' Compliance',
        };
    }

    /**
     * The formatted "From" header value: "Display Name <email>".
     */
    public function fromHeader(): string
    {
        return $this->senderName().' <'.$this->senderAddress().'>';
    }

    /**
     * What a customer receives from this mailbox, in their words. Published on
     * the Contact page so someone can check a suspicious email against the
     * real list rather than guessing which senders are genuinely ours.
     */
    public function customerDescription(): string
    {
        return match ($this) {
            self::Verify => 'Email confirmation and account verification links',
            self::Security => 'Login alerts, one-time codes and new device notices',
            self::Wallet => 'Wallet funding, withdrawals, transfers and Rcoin',
            self::Orders => 'Order confirmations, delivery and fulfillment updates',
            self::Transactions => 'Payment receipts, failures and abandoned checkout reminders',
            self::Notifications => 'Service notices, maintenance and product updates',
            self::Marketing => 'Newsletters and promotions, only if you opted in',
            self::Compliance => 'KYC decisions and regulatory or policy changes',
        };
    }

    /**
     * The Reply-To address — always routes to support.
     */
    public static function replyTo(): string
    {
        return 'support@rshoprefill.com';
    }

    /**
     * Whether this mailbox's emails can never be suppressed by user preferences.
     */
    public function isMandatory(): bool
    {
        return in_array($this, [
            self::Verify,
            self::Security,
            self::Compliance,
        ], true);
    }
}
