<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the rate limiter needs to record that it could not before.
 *
 * A card-testing lock lasts hours, so an admin needs a way to end one early
 * when the alert turns out to be a real customer. Lifting stamps the event
 * rather than deleting it, so the report still shows the lock happened and who
 * released it.
 *
 * And the pauses are now driven by failed attempts on every payment rail, not
 * by refused cards or no-shows alone, so the attempt count behind each decision
 * gets its own column. The older decline and no-show columns stay for the rows
 * already written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_backoff_events', function (Blueprint $table) {
            $table->unsignedInteger('attempt_count')->default(0)->after('tier');
            $table->dateTime('lifted_at')->nullable()->after('enforced');
            $table->unsignedBigInteger('lifted_by')->nullable()->after('lifted_at');

            $table->index(['signal', 'device_uuid', 'created_at']);
            $table->index(['identifier', 'signal', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_backoff_events', function (Blueprint $table) {
            $table->dropIndex(['identifier', 'signal', 'created_at']);
            $table->dropIndex(['signal', 'device_uuid', 'created_at']);
            $table->dropColumn(['attempt_count', 'lifted_at', 'lifted_by']);
        });
    }
};
