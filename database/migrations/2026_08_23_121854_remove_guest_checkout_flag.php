<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guest purchase is removed from the product: checkout is signed-in-only, so
 * the features.guest_checkout_enabled toggle has nothing left to switch. The
 * users.is_guest column stays - legacy shadow accounts from the old flow
 * keep their claim path (set a password + PIN via the emailed signed link).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_settings')->where('key', 'features.guest_checkout_enabled')->delete();
    }

    public function down(): void
    {
        // no-op: the flag's feature no longer exists to re-enable.
    }
};
