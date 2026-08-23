<?php

namespace App\Domain\Kyc\Contracts;

use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Models\User;

/**
 * The seam every identity-verification vendor plugs into. The tier engine talks
 * only to this contract, so swapping Sumsub for Smile Identity, Dojah, or the
 * built-in manual reviewer is a config change, never an engine change.
 *
 * Two responsibilities: start a verification (hand back where to send the
 * customer), and turn the vendor's asynchronous webhook into a normalised
 * verdict the engine can act on. Nothing here exposes a vendor's payload shape.
 */
interface KycProvider
{
    /**
     * The provider's stable key (e.g. 'manual', 'sumsub', 'smile'). Stored on
     * the submission so a verdict is always reconciled against the provider that
     * issued it.
     */
    public function key(): string;

    /**
     * Begin an identity-verification session for the user and return where to
     * send them to complete it.
     *
     * @param  string|null  $issuingCountry  ISO code of the country that issued
     *                                       the customer's ID, when they chose
     *                                       one explicitly; null = infer from
     *                                       their profile. Their documents are
     *                                       not always from their profile
     *                                       country (diaspora, travellers).
     */
    public function startVerification(User $user, ?string $issuingCountry = null): KycSession;

    /**
     * Normalise an inbound provider webhook into a verdict, or null when the
     * payload is not a decision this provider recognises (heartbeat, unknown
     * event). Signature/authenticity checks belong in the implementation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verdictFromWebhook(array $payload): ?KycVerdict;
}
