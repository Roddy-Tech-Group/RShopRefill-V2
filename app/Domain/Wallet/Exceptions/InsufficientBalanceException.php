<?php

namespace App\Domain\Wallet\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    public static function forWallet(int $walletId, float $required, float $available): self
    {
        return new self("Wallet [{$walletId}] has insufficient balance. Required: {$required}, Available: {$available}.");
    }

    /**
     * No wallet the customer holds covers the order. Shown to the customer
     * as written, so it says what to do next and names no internals.
     */
    public static function forOrder(): self
    {
        return new self('None of your wallets has enough to cover this order. Add funds or choose another payment method.');
    }

    /**
     * The wallet the customer picked does not cover the order. We refuse
     * rather than quietly charge a different wallet.
     */
    public static function forChosenWallet(string $currency, string $needed): self
    {
        return new self("Your {$currency} wallet does not cover this order ({$needed} needed). Pick another wallet or add funds.");
    }
}
