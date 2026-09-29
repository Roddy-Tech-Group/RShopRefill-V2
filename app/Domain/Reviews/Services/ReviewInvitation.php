<?php

namespace App\Domain\Reviews\Services;

use App\Models\SiteSetting;

/**
 * Automated review invitations by blind copy.
 *
 * The review platform gives each business an address to blind-copy on its
 * order emails; it reads the customer from the copy and sends its own
 * invitation a few days later. Admins paste that address into the
 * reviews.invite_bcc_address setting. Empty, or anything that is not an email
 * address, means no invitations go out.
 *
 * The copy only ever rides on the order-complete note, never on a delivery
 * email: those carry gift card codes, PINs and eSIM activation details, and a
 * blind copy would hand every one of them to a third party.
 */
class ReviewInvitation
{
    public const SETTING_KEY = 'reviews.invite_bcc_address';

    /**
     * The address to blind-copy, or null when invitations are off.
     */
    public static function bccAddress(): ?string
    {
        $raw = SiteSetting::get(self::SETTING_KEY, '');

        // The settings page stores whatever JSON-decodes, so a pasted list
        // arrives as an array. One plain address or nothing.
        if (! is_string($raw)) {
            return null;
        }

        $address = trim($raw);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $address;
    }
}
