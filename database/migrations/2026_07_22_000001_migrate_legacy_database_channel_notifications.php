<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy notifications were stored with channel = 'database', which is
     * not a valid CommunicationChannel enum value — hydrating any of those
     * rows through the Notification model throws. Normalise them to the
     * platform's 'in_app' channel.
     */
    public function up(): void
    {
        DB::table('notifications')
            ->where('channel', 'database')
            ->update(['channel' => 'in_app']);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('channel', 'in_app')
            ->update(['channel' => 'database']);
    }
};
