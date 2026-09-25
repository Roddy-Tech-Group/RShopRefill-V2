<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per full pass over a supplier catalog feed. The cleanup that
        // hides offers a supplier dropped only ever runs from a completed row,
        // and the previous completed row is the baseline the shrink guard
        // compares against. dateTime (not timestamp) so MySQL never attaches
        // an implicit ON UPDATE CURRENT_TIMESTAMP to started_at.
        Schema::create('catalog_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('feed', 32);
            $table->string('status', 20);
            $table->boolean('force_cleanup')->default(false);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('offers_seen')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->string('cleanup', 20)->nullable();
            $table->string('cleanup_skipped_reason')->nullable();
            $table->unsignedInteger('offers_delisted')->default(0);
            $table->unsignedInteger('offers_restored')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['feed', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_runs');
    }
};
