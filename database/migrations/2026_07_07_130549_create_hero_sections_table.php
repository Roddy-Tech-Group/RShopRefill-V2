<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Homepage hero sections, managed from admin > Content > Hero Sections.
     *
     * The designed storefront hero ships as a built-in row (is_builtin) so the
     * admin can toggle it off or replace its dotted backdrop with uploaded
     * media, but its headline/CTAs stay the designed originals in the Blade
     * component. Extra rows become additional slides on the homepage.
     */
    public function up(): void
    {
        Schema::create('hero_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('subtitle', 500)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 500)->nullable();
            // Relative to public/assets/, e.g. "hero/summer-sale-x1y2z3.mp4"
            $table->string('media_path', 500)->nullable();
            $table->string('media_type', 10)->nullable(); // image | video
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the built-in designed hero so the storefront renders exactly as
        // before this feature shipped: one enabled slide, no media override.
        DB::table('hero_sections')->insert([
            'title' => 'Default hero',
            'is_builtin' => true,
            'is_enabled' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_sections');
    }
};
