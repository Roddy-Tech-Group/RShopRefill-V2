<?php

namespace App\Support;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A customer's eSIMs, one entry per eSIM bought, lifted out of their orders so
 * the dashboard's My eSIMs page can list them without the customer digging
 * through order history.
 *
 * An order item counts as an eSIM when it sits in the `esims` category or its
 * delivery payload carries eSIM credentials (ICCID, QR code, SM-DP+ address).
 * A top-up bought for an existing eSIM is not a new eSIM: it is counted against
 * the eSIM whose ICCID it refilled. Unpaid checkouts (pending) and cancelled
 * orders are left out; failed ones stay, so the customer can see what happened.
 */
final class CustomerEsims
{
    /**
     * @return Collection<int, array<string, mixed>> newest first
     */
    public static function for(User $user): Collection
    {
        $items = self::query($user)->with('order')->get()
            ->sortByDesc(fn (OrderItem $item) => $item->order?->placed_at ?? $item->order?->created_at ?? $item->created_at)
            ->values();

        [$topups, $esims] = $items->partition(fn (OrderItem $item) => self::isTopup($item));

        $topupsByIccid = $topups
            ->groupBy(fn (OrderItem $item) => (string) (($item->metadata ?? [])['parent_iccid'] ?? ''))
            ->map->count();

        $countryNames = array_flip((array) config('countries.codes', []));

        return $esims->map(fn (OrderItem $item) => self::present($item, $topupsByIccid, $countryNames))->values();
    }

    /**
     * Every order item of this customer that looks like an eSIM, top-ups included.
     */
    public static function query(User $user): Builder
    {
        $esimCategoryId = Category::query()->where('slug', 'esims')->value('id');

        return OrderItem::query()
            ->whereHas('order', fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->whereNotIn('order_status', ['pending', 'cancelled']))
            ->where(function (Builder $q) use ($esimCategoryId) {
                if ($esimCategoryId) {
                    $q->where('category_id', $esimCategoryId);
                }

                $q->orWhereNotNull('fulfillment_payload->iccid')
                    ->orWhereNotNull('fulfillment_payload->qrcode_url');
            });
    }

    private static function isTopup(OrderItem $item): bool
    {
        return (bool) (($item->metadata ?? [])['is_topup'] ?? false)
            || (bool) (($item->product_snapshot ?? [])['is_topup'] ?? false);
    }

    /**
     * @param  Collection<string, int>  $topupsByIccid
     * @param  array<string, string>  $countryNames  ISO code => country name
     * @return array<string, mixed>
     */
    private static function present(OrderItem $item, Collection $topupsByIccid, array $countryNames): array
    {
        $snap = (array) ($item->product_snapshot ?? []);
        $variant = (array) ($item->variant_snapshot ?? []);
        $meta = (array) ($variant['metadata'] ?? []);
        $raw = (array) ($meta['raw_payload'] ?? []);
        $payload = (array) ($item->fulfillment_payload ?? []);
        $manual = (array) ($payload['esim'] ?? []);

        $scalar = fn ($value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null;

        $iccid = $scalar($payload['iccid'] ?? null) ?? $scalar($manual['iccid'] ?? null);
        $countryCode = strtoupper((string) ($snap['country_code'] ?? ''));

        $status = match ($item->fulfillment_status) {
            FulfillmentStatus::Fulfilled, FulfillmentStatus::PartiallyFulfilled => 'delivered',
            FulfillmentStatus::Failed => 'failed',
            default => 'preparing',
        };

        return [
            'id' => $item->id,
            'order_number' => $item->order?->order_number,
            'purchased_at' => $item->order?->placed_at ?? $item->order?->created_at ?? $item->created_at,
            'name' => (string) ($snap['name'] ?? 'eSIM'),
            'country' => $countryCode !== '' ? ($countryNames[$countryCode] ?? $countryCode) : null,
            'data' => self::dataLabel($meta, $raw),
            'days' => (int) ($meta['validity_days'] ?? 0) ?: (int) ($raw['durationDays'] ?? 0),
            'status' => $status,
            'iccid' => $iccid,
            'qr' => $scalar($payload['qrcode_url'] ?? null),
            'lpa' => $scalar($payload['lpa'] ?? null),
            'code' => $scalar($manual['manualActivationCode'] ?? null),
            // iOS tap-to-install link (iOS 17.4+), no QR scan needed.
            'install' => $scalar($payload['direct_install_url'] ?? null) ?? $scalar($manual['directInstallUrl'] ?? null),
            // Hosted page where the customer monitors usage and re-installs.
            'manage' => $scalar($payload['sharing_link'] ?? null) ?? $scalar($manual['sharingLink'] ?? null),
            'access' => $scalar($payload['sharing_access_code'] ?? null) ?? $scalar($manual['sharingAccessCode'] ?? null),
            'can_top_up' => $iccid !== null && $item->provider_name === 'airalo' && $item->fulfillment_status === FulfillmentStatus::Fulfilled,
            'topups' => $iccid !== null ? (int) ($topupsByIccid[$iccid] ?? 0) : 0,
        ];
    }

    /**
     * Same reading as the eSIM store page: the provider's clean data_limit,
     * then the raw package's unlimited flag or GB figure.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $raw
     */
    private static function dataLabel(array $meta, array $raw): ?string
    {
        $limit = trim((string) ($meta['data_limit'] ?? ''));

        if ((bool) ($raw['dataUnlimited'] ?? false) || str_contains(strtolower($limit), 'unlimited')) {
            return 'Unlimited';
        }

        if ($limit !== '' && strcasecmp($limit, 'Unknown') !== 0) {
            return $limit;
        }

        $gb = (float) ($raw['dataGB'] ?? 0);

        return $gb > 0 ? rtrim(rtrim(number_format($gb, 2), '0'), '.').' GB' : null;
    }
}
