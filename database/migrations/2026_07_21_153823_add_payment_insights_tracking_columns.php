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
        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->index()->after('customer_ip');
            $table->string('device_uuid')->nullable()->index()->after('correlation_id');
            $table->foreignId('trusted_device_id')->nullable()->constrained()->nullOnDelete()->after('device_uuid');
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->index()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_device_id');
            $table->dropColumn(['correlation_id', 'device_uuid']);
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn('correlation_id');
        });
    }
};
