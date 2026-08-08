<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Providers\KorapayPaymentProvider;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Providers\PaystackPaymentProvider;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Shared\Enums\PaymentGateway;

class PaymentGatewayFactory
{
    /**
     * Gateways with a live provider class behind them. FiatGatewayRouter
     * consults this so a backup whose kill-switch flag is flipped on BEFORE
     * its provider ships is simply skipped, instead of resolving into a
     * getProvider() crash at payment initialization. Paystack and Kora Pay
     * join this list when their provider classes land.
     *
     * @var list<string>
     */
    public const IMPLEMENTED = ['wallet', 'flutterwave', 'paystack', 'korapay', 'crypto', 'nowpayments'];

    public function __construct(
        private readonly WalletPaymentProvider $walletProvider,
        private readonly FlutterwavePaymentProvider $flutterwaveProvider,
        private readonly NowPaymentsProvider $nowPaymentsProvider
    ) {}

    public static function isImplemented(string $gateway): bool
    {
        return in_array($gateway, self::IMPLEMENTED, true);
    }

    public function getProvider(string|PaymentGateway $gateway): PaymentProviderInterface
    {
        $value = $gateway instanceof PaymentGateway ? $gateway->value : $gateway;

        return match ($value) {
            'wallet', PaymentGateway::Wallet->value => $this->walletProvider,
            'flutterwave', PaymentGateway::Flutterwave->value => $this->flutterwaveProvider,
            // Resolved lazily: the constructor fails closed on a missing
            // secret, and the backup should only ever be constructed on the
            // rare requests FiatGatewayRouter actually routes to it.
            'paystack', PaymentGateway::Paystack->value => app(PaystackPaymentProvider::class),
            'korapay', PaymentGateway::Korapay->value => app(KorapayPaymentProvider::class),
            'crypto', 'nowpayments', PaymentGateway::NowPayments->value => $this->nowPaymentsProvider,
            default => throw new \InvalidArgumentException("Unsupported payment gateway: {$value}"),
        };
    }
}
