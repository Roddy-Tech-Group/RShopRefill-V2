<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Fills the gaps behind the Contact page's address and phone details.
 *
 * contact.address already existed, seeded with the description "Optional
 * physical address. Shown on the contact page when set." - but nothing ever
 * rendered it, so setting it in System Settings did nothing at all. The page
 * now reads it; these rows add the structured pieces it was missing.
 *
 * The existing contact.address value is cleared IF it currently holds an email
 * address. It was seeded empty and somewhere along the way took an email, so
 * rendering it as-is would print "info@rshoprefill.com" where a street address
 * belongs. Blank hides the card until a real address is entered.
 */
return new class extends Migration
{
    private const NEW_SETTINGS = [
        [
            'key' => 'contact.phone_secondary',
            'description' => 'Optional second phone number. Shown on the contact page beside the primary number when set. Leave empty to show only one.',
        ],
        [
            'key' => 'contact.phone_secondary_label',
            'description' => 'Label for the second phone number (e.g. "Cameroon office", "WhatsApp only"). Defaults to "Alternate line".',
        ],
        [
            'key' => 'contact.address_maps_url',
            'description' => 'Optional Google Maps link for the physical address. Adds a "View on map" link to the address card.',
        ],
    ];

    public function up(): void
    {
        foreach (self::NEW_SETTINGS as $setting) {
            if (! SiteSetting::query()->where('key', $setting['key'])->exists()) {
                SiteSetting::put($setting['key'], '', 'contact', $setting['description']);
            }

            Cache::forget('site_setting:'.$setting['key']);
        }

        // Only clear it when it is obviously the wrong kind of value. A real
        // address entered by an admin is never touched.
        $address = SiteSetting::query()->where('key', 'contact.address')->first();
        if ($address !== null && filter_var(trim((string) $address->value), FILTER_VALIDATE_EMAIL)) {
            SiteSetting::put(
                'contact.address',
                '',
                'contact',
                'Physical address, shown as a card under the contact form. Supports multiple lines.',
            );
        }

        Cache::forget('site_setting:contact.address');
    }

    public function down(): void
    {
        foreach (self::NEW_SETTINGS as $setting) {
            SiteSetting::query()->where('key', $setting['key'])->delete();
            Cache::forget('site_setting:'.$setting['key']);
        }
    }
};
