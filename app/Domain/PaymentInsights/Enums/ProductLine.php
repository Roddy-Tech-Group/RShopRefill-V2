<?php

namespace App\Domain\PaymentInsights\Enums;

enum ProductLine: string
{
    case Checkout = 'checkout';
    case WalletFunding = 'wallet_funding';
}
