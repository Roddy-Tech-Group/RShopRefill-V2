<?php

namespace App\Domain\Communication\Services;

/**
 * The one answer to "can this address ever receive mail?".
 *
 * Both suppression sites (EmailChannel's pre-flight and NewsletterService's
 * subscribe guard) resolve through here so the blocked set can never diverge
 * between them. The set itself lives in config/communication.php
 * (undeliverable_domains), env-tunable per environment: the test suite blanks
 * it because every factory address is @example.com.
 */
final class UndeliverableDomains
{
    /**
     * Whether the address's domain is known to be undeliverable.
     */
    public static function blocks(string $email): bool
    {
        $at = strrchr($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($at, 1));

        return in_array($domain, (array) config('communication.undeliverable_domains', []), true);
    }
}
