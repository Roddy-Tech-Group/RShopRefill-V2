<?php

use App\Domain\Reviews\Services\ReviewInvitation;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * The review invitation address, seeded empty so it shows up in Admin >
 * System settings under "reviews" ready to be filled in. Empty means off: no
 * order-complete note is sent and nothing is copied anywhere.
 */
return new class extends Migration
{
    private const DESCRIPTION = 'Review invitations: paste the blind-copy (BCC) address from your review platform\'s automated invitations. A short order-complete email, which never contains codes, is copied to it when an order is fully delivered. Leave empty to send no invitations.';

    public function up(): void
    {
        if (SiteSetting::query()->where('key', ReviewInvitation::SETTING_KEY)->exists()) {
            return;
        }

        SiteSetting::put(ReviewInvitation::SETTING_KEY, '', 'reviews', self::DESCRIPTION);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', ReviewInvitation::SETTING_KEY)->delete();
    }
};
