<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account closure replaces hard deletion. `closure_requested_at` starts the
 * grace period; `anonymised_at` records when the personal details were scrubbed
 * and the row became a pseudonymous holder for the records financial law makes
 * us keep. Both nullable, so no existing row changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('closure_requested_at')->nullable()->after('suspension_review_requested_at');
            $table->timestamp('anonymised_at')->nullable()->after('closure_requested_at');
            $table->index('closure_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['closure_requested_at']);
            $table->dropColumn(['closure_requested_at', 'anonymised_at']);
        });
    }
};
