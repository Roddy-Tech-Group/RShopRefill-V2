<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * The experience prompt's switches. It ships OFF: nothing asks a customer for a
 * rating until an admin turns it on in System Settings. The delay is the number
 * of minutes on the dashboard before the first ask of the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'features.experience_prompt_enabled', 'value' => 'off', 'type' => 'boolean', 'description' => 'Ask signed-in customers to rate their experience, once a day until they answer.', 'group' => 'features'],
            ['key' => 'features.experience_prompt_delay_minutes', 'value' => '3', 'type' => 'integer', 'description' => 'Minutes on the dashboard before the experience prompt appears.', 'group' => 'features'],
        ];

        foreach ($settings as $setting) {
            SiteSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }

    public function down(): void
    {
        SiteSetting::whereIn('key', [
            'features.experience_prompt_enabled',
            'features.experience_prompt_delay_minutes',
        ])->delete();
    }
};
