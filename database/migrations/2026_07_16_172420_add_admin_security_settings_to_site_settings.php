<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            ['key' => 'features.admin_inactivity_timeout_enabled', 'value' => 'on', 'type' => 'boolean', 'description' => 'Enable auto-logout for inactive admin sessions.', 'group' => 'features'],
            ['key' => 'features.admin_inactivity_timeout_minutes', 'value' => '30', 'type' => 'integer', 'description' => 'Minutes of inactivity before an admin is automatically logged out.', 'group' => 'features'],
            ['key' => 'features.admin_security_question_enabled', 'value' => 'on', 'type' => 'boolean', 'description' => 'Require admins with a security question to answer it during login', 'group' => 'features'],
        ];

        foreach ($settings as $setting) {
            \App\Models\SiteSetting::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \App\Models\SiteSetting::whereIn('key', [
            'features.admin_inactivity_timeout_enabled',
            'features.admin_inactivity_timeout_minutes',
            'features.admin_security_question_enabled',
        ])->delete();
    }
};
