<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'rate_per_usd',
        'icon_path',
        'sort_order',
        'is_active',
        'network',
        'min_amount_usd',
        'requires_extra_id',
        'is_payable',
        'limits_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_per_usd' => 'decimal:8',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'requires_extra_id' => 'boolean',
            'is_payable' => 'boolean',
            'limits_synced_at' => 'datetime',
        ];
    }

    /**
     * Coins the storefront may offer: enabled by us AND accepted by the gateway.
     */
    public function scopePayable($query)
    {
        return $query->where('is_active', true)->where('is_payable', true);
    }

    /**
     * Whether an order of this USD value clears the gateway's minimum for this coin.
     *
     * Fails OPEN when the minimum is unknown. A missing sync must not silently
     * remove every payment option from the checkout — the gateway still rejects a
     * genuinely sub-minimum payment, so the worst case is the old behaviour rather
     * than an unpayable store.
     */
    public function acceptsOrderValue(float $usd): bool
    {
        if ($this->min_amount_usd === null) {
            return true;
        }

        return $usd >= (float) $this->min_amount_usd;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFiat($query)
    {
        return $query->where('type', 'fiat');
    }

    public function scopeCrypto($query)
    {
        return $query->where('type', 'crypto');
    }

    /**
     * Resolve the active rate row for a currency code. Falls back to the USD row,
     * then to a synthetic 1:1 USD rate, so callers always get a usable object even
     * if the table is empty or the requested currency is missing/inactive.
     */
    public static function resolve(?string $code): self
    {
        $code = strtoupper(trim((string) $code)) ?: 'USD';

        $rate = static::query()->where('is_active', true)->where('code', $code)->first()
            ?? static::query()->where('is_active', true)->where('code', 'USD')->first();

        return $rate ?? new self([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'type' => 'fiat',
            'rate_per_usd' => 1.0,
        ]);
    }

    /**
     * Convert a USD amount into this currency using rate_per_usd.
     */
    public function convert(float $usd): float
    {
        return $usd * (float) $this->rate_per_usd;
    }

    /**
     * Full URL to the currency icon when set. Falls back to null so the view can
     * render a coloured initial badge instead.
     *
     * Fingerprinted with the file's modification time. Coin icons are static
     * filenames that occasionally get replaced, and browsers (and any CDN in
     * front) will happily serve a cached miss or an old version indefinitely —
     * which shows up as a blank tile that looks like a broken integration. The
     * query string changes whenever the file does, so a replacement is picked up
     * immediately without anyone needing to hard-refresh.
     *
     * Falls back to the bare URL if the file is missing, so a bad icon_path
     * degrades to a broken image rather than an exception.
     */
    public function iconUrl(): ?string
    {
        if (! $this->icon_path) {
            return null;
        }

        $url = asset('assets/'.$this->icon_path);
        $path = public_path('assets/'.$this->icon_path);

        return is_file($path) ? $url.'?v='.filemtime($path) : $url;
    }
}
