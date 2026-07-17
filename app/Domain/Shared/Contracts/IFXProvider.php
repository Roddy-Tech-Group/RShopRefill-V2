<?php

namespace App\Domain\Shared\Contracts;

interface IFXProvider
{
    /**
     * Get an exchange rate quote between two currencies.
     */
    public function getQuote(string $sourceCurrency, string $destinationCurrency, int $amount): array;

    /**
     * Execute an exchange between currencies.
     */
    public function executeExchange(string $quoteId): array;
}
