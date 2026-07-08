<?php

use App\Http\Controllers\EsimStoreController;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Homepage hero highlight cards (the Apple-style tiles right under the
     * hero), managed from admin > Content > Hero Cards. Seeds the three
     * original cards so the storefront looks identical after the swap from
     * hardcoded Blade to CMS rows.
     */
    public function up(): void
    {
        Schema::create('hero_cards', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle', 300)->nullable();
            // Relative to public/assets/, e.g. "hero-cards/apple-x1y2.png"
            $table->string('image_path', 500);
            // Horizontal focal point (0-100) used as CSS object-position so
            // landscape art keeps its subject in frame inside the square crop.
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->string('link_url', 500)->nullable();
            $table->string('learn_url', 500)->nullable();
            $table->string('buy_url', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $siteName = 'RshopRefills';
        try {
            $siteName = (string) SiteSetting::get('site.name', 'RshopRefills');
        } catch (Throwable $e) {
            // Settings table may not exist yet on a fresh install; keep default.
        }

        // Best effort: point the eSIM card at the Discover Global product page
        // (same product the homepage Discover Global row sells). Falls back to
        // the eSIM landing when the catalog is not synced yet.
        $esimUrl = '/esims';
        try {
            $product = EsimStoreController::discoverGlobal()['product'] ?? null;
            if ($product) {
                $esimUrl = '/esims/'.$product->slug;
            }
        } catch (Throwable $e) {
            // Catalog empty or controller unavailable mid-migration; keep fallback.
        }

        $now = now();
        DB::table('hero_cards')->insert([
            [
                'title' => 'Buy Apple Gift Cards',
                'subtitle' => 'Instant codes, delivered by email.',
                'image_path' => 'Hero Section 3.png',
                'focal_x' => 68,
                'link_url' => '/gift-cards/apple',
                'learn_url' => '/help',
                'buy_url' => '/gift-cards/apple',
                'is_enabled' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Discover Global eSIM',
                'subtitle' => '365-day plans that keep you connected worldwide.',
                'image_path' => 'Hero 2 Shop.png',
                'focal_x' => 62,
                'link_url' => $esimUrl,
                'learn_url' => '/help',
                'buy_url' => $esimUrl,
                'is_enabled' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'The '.$siteName.' dashboard',
                'subtitle' => 'Orders, wallet and rewards in one place.',
                'image_path' => 'Hero section 4.png',
                'focal_x' => 50,
                'link_url' => '/dashboard',
                'learn_url' => '/help',
                'buy_url' => null,
                'is_enabled' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_cards');
    }
};
