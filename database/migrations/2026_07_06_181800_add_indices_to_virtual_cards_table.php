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
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('provider_card_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['provider_card_id']);
            $table->dropIndex(['status']);
        });
    }
};
