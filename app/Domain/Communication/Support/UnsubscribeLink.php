<?php

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The signed, login-free link that turns one category of optional email off
 * for one customer. It goes into the List-Unsubscribe header (so Gmail and
 * Apple Mail show their own unsubscribe control) and into the email footer.
 *
 * Deliberately never expires: the link in a month-old email must still work,
 * or the customer's only remaining option is the spam button, which costs the
 * whole sending domain.
 */
final class UnsubscribeLink
{
    public static function for(User $user, CommunicationCategory $category): string
    {
        return URL::signedRoute('email.unsubscribe', [
            'user' => $user->getKey(),
            'category' => $category->value,
        ]);
    }
}
