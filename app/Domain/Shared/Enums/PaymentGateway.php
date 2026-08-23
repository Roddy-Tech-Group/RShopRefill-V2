<?php

namespace App\Domain\Shared\Enums;

/**
 * Supported payment gateways.
 *
 * flutterwave  → Primary fiat gateway (cards, bank transfers, mobile money).
 * paystack     → Fiat backup: cards / Apple Pay / USSD / NGN transfers,
 *                GHS-KES-XOF mobile money. Active only while Flutterwave is off.
 * korapay      → Fiat backup: XAF/XOF/EGP/TZS mobile money, NGN bank rails.
 *                Active only while Flutterwave is off.
 * nowpayments  → Cryptocurrency payments.
 * wallet       → Internal wallet balance.
 */
enum PaymentGateway: string
{
    case Flutterwave = 'flutterwave';
    case Paystack = 'paystack';
    case Korapay = 'korapay';
    case NowPayments = 'nowpayments';
    case Wallet = 'wallet';

    /**
     * Get a human-readable label for the gateway.
     */
    public function label(): string
    {
        return match ($this) {
            self::Flutterwave => 'Flutterwave',
            self::Paystack => 'Paystack',
            self::Korapay => 'Kora Pay',
            self::NowPayments => 'NowPayments',
            self::Wallet => 'Wallet',
        };
    }
}
