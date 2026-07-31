<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaProcessorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $productId,
        private readonly string $url
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            return;
        }

        try {
            $response = Http::get($this->url);

            if ($response->successful()) {
                // Determine extension (rudimentary, better to check content-type)
                $extension = 'png';
                $contentType = $response->header('Content-Type');
                if (str_contains($contentType, 'jpeg')) {
                    $extension = 'jpg';
                }
                if (str_contains($contentType, 'svg')) {
                    $extension = 'svg';
                }

                $filename = "catalog/products/{$product->slug}-".Str::random(5).".{$extension}";

                Storage::disk('public')->put($filename, $response->body());

                $product->update([
                    'logo_url' => Storage::url($filename),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to process media for product {$this->productId}", [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
