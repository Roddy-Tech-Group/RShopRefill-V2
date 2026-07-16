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
        $setting = [
            'key' => 'features.device_restriction_enabled',
            'value' => 'on',
            'type' => 'boolean',
            'description' => 'Enforce 24-hour holds on critical actions for new devices',
            'group' => 'features'
        ];

        \App\Models\SiteSetting::firstOrCreate(
            ['key' => $setting['key']],
            $setting
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \App\Models\SiteSetting::where('key', 'features.device_restriction_enabled')->delete();
    }
};
