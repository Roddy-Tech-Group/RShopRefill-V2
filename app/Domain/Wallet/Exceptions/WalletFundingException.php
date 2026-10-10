<?php

namespace App\Domain\Wallet\Exceptions;

use Exception;

class WalletFundingException extends Exception
{
    public static function alreadyProcessed(string $reference): self
    {
        return new self("Wallet funding [{$reference}] has already been processed.");
    }

    /** The gateway could not be asked; the funding is left as it is for a later pass. */
    public static function verificationUnavailable(string $reference): self
    {
        return new self("Funding [{$reference}] could not be verified with the gateway right now; it will be checked again.");
    }

    public static function verificationFailed(string $reference, string $reason): self
    {
        return new self("Funding verification failed for [{$reference}]: {$reason}");
    }
}
