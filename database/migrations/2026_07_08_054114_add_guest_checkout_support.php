<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest checkout. Guests order under a shadow account keyed by their delivery
 * email (is_guest = true, random password). After fulfillment they receive a
 * claim email; setting a password + transaction PIN converts the shadow
 * account into a real one with their guest orders already attached.
 * features.guest_checkout_enabled (default ON) lets the admin switch the
 * whole flow off from System Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('email_verified_at');
            // Throttles the claim email to one per hour per guest (multi-item
            // orders fire one fulfillment event per item).
            $table->timestamp('guest_claim_last_sent_at')->nullable()->after('is_guest');
        });

        if (! SiteSetting::query()->where('key', 'features.guest_checkout_enabled')->exists()) {
            SiteSetting::put(
                'features.guest_checkout_enabled',
                'on',
                'features',
                'Off requires customers to sign in before completing checkout (guests can still browse and fill a cart).',
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_guest', 'guest_claim_last_sent_at']);
        });

        SiteSetting::query()->where('key', 'features.guest_checkout_enabled')->delete();
    }
};
