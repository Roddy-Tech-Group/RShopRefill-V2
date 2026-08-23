<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * device_management and device_restriction were read by the login/device
     * pipeline but never seeded, so both defaulted ON with no row an admin
     * could see or flip. Seed them ON (matching live behaviour) so the kill
     * switches are visible on the Settings page like every other flag.
     */
    public function up(): void
    {
        $features = [
            [
                'key' => 'features.device_management_enabled',
                'group' => 'features',
                'value' => 'on',
                'description' => 'Off disables device tracking at login: no device rows, no new-device OTP, and the new-device hold stops applying.',
            ],
            [
                'key' => 'features.device_restriction_enabled',
                'group' => 'features',
                'value' => 'on',
                'description' => 'Off lifts the 24-hour hold that blocks new devices from changing the password or PIN, revoking devices, and relaxing OTP preferences.',
            ],
        ];

        foreach ($features as $feature) {
            SiteSetting::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }

    public function down(): void
    {
        SiteSetting::whereIn('key', [
            'features.device_management_enabled',
            'features.device_restriction_enabled',
        ])->delete();
    }
};
