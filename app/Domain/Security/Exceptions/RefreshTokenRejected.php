<?php

namespace App\Domain\Security\Exceptions;

use RuntimeException;

/**
 * Why a refresh token was refused, in a form the API can turn into an error
 * `type` the app branches on.
 *
 * The four cases are kept apart deliberately. To the customer they all mean
 * "sign in again", but the app should only wipe its stored tokens and show the
 * login screen, never retry, and we need to be able to tell an ordinary expiry
 * apart from a replay when reading logs.
 */
final class RefreshTokenRejected extends RuntimeException
{
    private function __construct(
        public readonly string $type,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** No such token. A typo, a wiped database, or a guess. */
    public static function invalid(): self
    {
        return new self('refresh_token_invalid', 'Please sign in again.');
    }

    /** The phone sat unused past the refresh window. Entirely normal. */
    public static function expired(): self
    {
        return new self('refresh_token_expired', 'Your session has expired. Please sign in again.');
    }

    /** Signed out from this device, from another device, or by us. */
    public static function revoked(): self
    {
        return new self('refresh_token_revoked', 'You were signed out. Please sign in again.');
    }

    /**
     * A token that had already been exchanged, presented again outside the
     * retry grace. Either a stolen copy or a very confused client. Either way
     * the device's whole token family has just been cut loose.
     */
    public static function reused(): self
    {
        return new self('refresh_token_reused', 'You were signed out for security reasons. Please sign in again.');
    }
}
