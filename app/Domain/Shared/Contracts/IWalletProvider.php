<?php

namespace App\Domain\Shared\Contracts;

interface IWalletProvider
{
    /**
     * Create a wallet/virtual account for a customer on the provider.
     */
    public function createWallet(array $customerData): array;

    /**
     * Get the balance of the omnibus or specific wallet.
     */
    public function getBalance(string $currency, ?string $walletId = null): array;

    /**
     * Withdraw funds from the wallet to an external bank account.
     */
    public function withdraw(int $amount, string $currency, array $bankDetails): array;
}
