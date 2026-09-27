<?php

namespace App\Models;

use App\Domain\Catalog\Enums\CatalogFeed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'subcategory_id',
        'provider_offer_id',
        'catalog_feed',
        'sku',
        'currency',
        'face_value',
        'cost_price',
        'retail_price',
        'manual_retail_price_usd',
        'allow_below_cost',
        'min_amount',
        'max_amount',
        'is_variable',
        'is_available',
        'supplier_available',
        'admin_disabled_at',
        'admin_disabled_by',
        'last_seen_at',
        'delisted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'retail_price' => 'decimal:4',
            'manual_retail_price_usd' => 'decimal:4',
            'allow_below_cost' => 'boolean',
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
            'is_variable' => 'boolean',
            'is_available' => 'boolean',
            'supplier_available' => 'boolean',
            'catalog_feed' => CatalogFeed::class,
            'admin_disabled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'delisted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * An offer an admin switched off stays off, whatever the supplier feed says.
     *
     * Enforced here rather than in each normalizer on purpose. Four normalizers
     * write availability today and a fifth will exist one day; a rule that has
     * to be remembered in five places is a rule that gets forgotten in one, and
     * the way this was noticed the first time was a sync quietly putting a
     * deliberately disabled product back on sale.
     *
     * It only ever forces OFF. Nothing here can put an offer back on sale, so a
     * delisted or supplier-disabled variant cannot be resurrected by accident.
     */
    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            if ($variant->admin_disabled_at !== null) {
                $variant->is_available = false;
            }
        });
    }

    /**
     * Whether an admin, rather than the supplier, is the reason this is off sale.
     */
    public function isAdminDisabled(): bool
    {
        return $this->admin_disabled_at !== null;
    }

    /**
     * Put the offer back under the supplier's control: available again only if
     * the feed still lists it and no cleanup has delisted it.
     */
    public function clearAdminDisable(): void
    {
        $this->forceFill([
            'admin_disabled_at' => null,
            'admin_disabled_by' => null,
            'is_available' => (bool) $this->supplier_available && $this->delisted_at === null,
        ])->save();
    }

    /**
     * Take the offer off sale by hand, and keep it off across every sync.
     */
    public function adminDisable(?int $adminId = null): void
    {
        $this->forceFill([
            'admin_disabled_at' => now(),
            'admin_disabled_by' => $adminId,
            'is_available' => false,
        ])->save();
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The subcategory this specific offer belongs to. May differ from the
     * parent product's subcategory when a product spans multiple subtypes.
     */
    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    /**
     * The purchase fields the supplier needs to deliver this offer, as Zendit
     * declares them per offer (billing.accountId, recipient.msisdn, sender.*).
     * The catalogue sync keeps them in metadata. The category alone cannot say
     * what an offer needs: betting wallets and utility brands sync in as gift
     * cards, and a Kenyan gift card can want the recipient's phone number.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return array_values(array_filter((array) data_get($this->metadata, 'requiredFields', []), 'is_string'));
    }

    public function requiresField(string $key): bool
    {
        return in_array($key, $this->requiredFields(), true);
    }

    /**
     * The customer's account number (a meter number, a betting wallet ID).
     */
    public function requiresAccountId(): bool
    {
        return $this->requiresField('billing.accountId');
    }

    /**
     * The phone number the offer is delivered to.
     */
    public function requiresRecipientPhone(): bool
    {
        return $this->requiresField('recipient.msisdn');
    }

    /**
     * The buyer's own phone number, which some suppliers put on the receipt.
     */
    public function requiresSenderPhone(): bool
    {
        return $this->requiresField('sender.msisdn');
    }

    /**
     * What this offer needs typed on the product page and the cart does not
     * have, as validation messages keyed the way the page names its inputs.
     * The cart refuses the item on any of them: an offer the supplier will
     * refuse to deliver must never be paid for. The buyer's own number is
     * satisfied by their profile, so nobody is asked for a number we hold.
     *
     * @param  array<string, mixed>  $metadata  the cart item metadata being added
     * @return array<string, string>
     */
    public function missingPurchaseFields(array $metadata, ?User $buyer = null): array
    {
        $typed = fn (string $key): bool => trim((string) data_get($metadata, $key, '')) !== '';

        $missing = [];

        if ($this->requiresAccountId() && ! $typed('account_id')) {
            $missing['metadata.account_id'] = 'Enter the account number this should be paid into.';
        }

        if ($this->requiresRecipientPhone() && ! $typed('recipient_phone')) {
            $missing['metadata.recipient_phone'] = 'Enter the phone number this should be sent to.';
        }

        if ($this->requiresSenderPhone() && ! $typed('sender_phone') && trim((string) ($buyer?->phone ?? '')) === '') {
            $missing['metadata.sender_phone'] = 'Enter your phone number.';
        }

        return $missing;
    }
}
