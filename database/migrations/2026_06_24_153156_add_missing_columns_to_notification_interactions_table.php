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
        Schema::table('notification_interactions', function (Blueprint $table) {
            $table->string('user_agent')->nullable()->after('channel');
            $table->ipAddress('ip_address')->nullable()->after('user_agent');
            $table->timestamps();

            if (Schema::hasColumn('notification_interactions', 'interacted_at')) {
                try {
                    $table->dropIndex('notification_interactions_interacted_at_index');
                } catch (Exception $e) {
                    try {
                        $table->dropIndex(['interacted_at']);
                    } catch (Exception $inner) {
                        // ignore if index not present
                    }
                }
                $table->dropColumn('interacted_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_interactions', function (Blueprint $table) {
            $table->dropColumn(['user_agent', 'ip_address', 'created_at', 'updated_at']);
            $table->timestamp('interacted_at')->nullable();
        });
    }
};
