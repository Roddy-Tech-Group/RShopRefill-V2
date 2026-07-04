<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * author_avatar: the poster's photo (relative to public/assets/), shown in the
     * byline next to their name. video_urls: pasted YouTube/Vimeo links embedded on
     * the post; a JSON array so a post can carry more than one.
     */
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->string('author_avatar')->nullable()->after('author');
            $table->json('video_urls')->nullable()->after('attachment_label');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['author_avatar', 'video_urls']);
        });
    }
};
