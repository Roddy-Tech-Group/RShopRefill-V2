<?php

namespace App\Domain\Shared\Contracts;

interface ICardProvider
{
    /**
     * Create a virtual card for a user.
     */
    public function createVirtualCard(array $data): array;

    /**
     * Fund a virtual card from the omnibus wallet.
     */
    public function fundCard(string $cardId, int $amount, string $currency): array;

    /**
     * Freeze a virtual card.
     */
    public function freezeCard(string $cardId): bool;

    /**
     * Unfreeze a virtual card.
     */
    public function unfreezeCard(string $cardId): bool;

    /**
     * Terminate a virtual card.
     */
    public function terminateCard(string $cardId): bool;

    /**
     * Get transactions for a card.
     */
    public function getCardTransactions(string $cardId, array $filters = []): array;
}
