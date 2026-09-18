<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate-your-experience prompt writes into the same reviews table the
 * product-page form does, so one moderation queue and one carousel serve both.
 * It adds what the prompt collects and the form never did: the 1-10 score
 * (rating keeps it halved for the stars), the service line the question named,
 * and whether the customer asked to stay anonymous. The comment is optional
 * there, so body can be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('rating');
            $table->string('category', 80)->nullable()->after('source');
            $table->boolean('is_anonymous')->default(false)->after('is_customer_submitted');
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['score', 'category', 'is_anonymous']);
            $table->text('body')->nullable(false)->change();
        });
    }
};
