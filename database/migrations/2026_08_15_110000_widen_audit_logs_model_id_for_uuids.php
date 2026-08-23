<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs.model_id was created by nullableMorphs() as an unsigned bigint,
 * which fit when only users (integer ids) were audited. The trail now targets
 * UUID-keyed models too (orders, risk reviews), and MySQL truncated the UUID
 * and threw on the first order-report generation. Widened to a string that
 * holds either shape; the morph index survives the type change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('model_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('model_id')->nullable()->change();
        });
    }
};
