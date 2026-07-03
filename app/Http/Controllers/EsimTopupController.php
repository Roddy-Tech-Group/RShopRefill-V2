<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Domain\Wallet\Services\WalletService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Native top-ups for fulfilled Airalo eSIMs. The customer picks one of the
 * packages Airalo offers for their existing ICCID and pays from their wallet;
 * we create an Order + OrderItem in the same shape as a fresh eSIM purchase,
 * tag it with the parent ICCID in metadata, and let the existing fulfilment
 * pipeline (FulfillOrderItemJob → AiraloFulfillmentProvider) take over.
 *
 * Routes:
 *   GET  /dashboard/esims/{orderItem}/top-up
 *   POST /dashboard/esims/{orderItem}/top-up
 */
class EsimTopupController extends Controller
{
    public function __construct(
        private readonly AiraloFulfillmentProvider $provider,
        private readonly WalletService $walletService,
        private readonly CartPricingService $pricingService,
    ) {}

    /**
     * Show the available top-up packages for the customer's eSIM.
     */
    public function show(Request $request, OrderItem $orderItem)
    {
        $this->authorizeAccess($request, $orderItem);

        $iccid = $this->extractIccid($orderItem);
        abort_if(! $iccid, 404, 'No ICCID on file for this eSIM yet.');

        // Cache for 10 minutes — Airalo's catalog changes slowly and this
        // saves a partner-API call on every page-view.
        $rawPackages = Cache::remember("airalo_topups_{$iccid}", now()->addMinutes(10), function () use ($iccid) {
            return $this->provider->listTopupsForIccid($iccid);
        });

        // Precompute the retail USD price (cost + markup hierarchy) on each
        // package so the view can render the final figure without knowing
        // about pricing rules. We also re-validate this server-side on POST.
        $parentProduct = $orderItem->product;
        $packages = collect($rawPackages)->map(function (array $pkg) use ($parentProduct) {
            $netCost = (float) ($pkg['net_price'] ?? $pkg['price'] ?? 0);
            $retail = $netCost > 0
                ? $this->pricingService->resolveRetailPrice($parentProduct, $netCost)
                : 0.0;
            $pkg['retail_usd'] = round($retail, 2);

            return $pkg;
        })->all();

        return view('shop.esim-topup', [
            'orderItem' => $orderItem,
            'iccid' => $iccid,
            'packages' => $packages,
        ]);
    }

    /**
     * Purchase a top-up package against the customer's eSIM. Debits the
     * wallet, creates a new Order + OrderItem, and dispatches the fulfilment
     * job. The job's provider routes to /orders/topups because metadata.parent_iccid
     * is set.
     */
    public function purchase(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $this->authorizeAccess($request, $orderItem);

        $validated = $request->validate([
            'package_id' => ['required', 'string', 'max:100'],
            // Net price from Airalo (USD) — server re-validates against the
            // cached package list so a tampered form can't pay less.
            'net_price' => ['required', 'numeric', 'min:0.01'],
            'wallet_pin' => ['required', 'string', 'size:4'],
        ]);

        $iccid = $this->extractIccid($orderItem);
        abort_if(! $iccid, 404, 'No ICCID on file for this eSIM yet.');

        // Re-verify the package + price against the live list. Defends against
        // stale client state and price tampering. These are all RECOVERABLE
        // states (provider hiccup, package gone, no funds) - they must surface a
        // friendly message on the top-up page, never a raw 422 error page.
        try {
            $packages = $this->provider->listTopupsForIccid($iccid);
        } catch (\Throwable $e) {
            Log::warning('eSIM top-up: could not fetch live packages', ['iccid' => $iccid, 'error' => $e->getMessage()]);

            return $this->topupError($orderItem, 'We could not reach the eSIM provider just now. Please try again in a moment.');
        }

        $package = collect($packages)->firstWhere('id', $validated['package_id']);
        if (! $package) {
            Log::warning('eSIM top-up: selected package no longer in live list', [
                'iccid' => $iccid,
                'package_id' => $validated['package_id'],
                'available' => count($packages),
            ]);

            return $this->topupError($orderItem, 'That top-up package is no longer available. Please choose another.');
        }

        $netCost = (float) ($package['net_price'] ?? $package['price'] ?? 0);
        if ($netCost <= 0) {
            return $this->topupError($orderItem, 'That package has an invalid price right now. Please choose another.');
        }

        // Reuse the canonical markup chain (rule hierarchy, safety floor)
        // — same maths as the rest of the catalog.
        $retail = round(
            $this->pricingService->resolveRetailPrice($orderItem->product, $netCost),
            2
        );

        $user = $request->user();

        if (! $user->hasTransactionPin()) {
            return $this->topupError($orderItem, 'Set up a Wallet Transaction PIN in your profile settings before topping up with your wallet.');
        }

        // Verify the provided PIN (throws ValidationException if incorrect or locked)
        app(TransactionPinService::class)->verifyPin($user, $validated['wallet_pin']);

        $wallet = $user->wallets()->where('currency', 'USD')->where('is_active', true)->first();
        if (! $wallet) {
            Log::warning('eSIM top-up: user has no active USD wallet', ['user_id' => $user->id]);

            return $this->topupError($orderItem, 'You need funds in your USD wallet to top up. Add money to your wallet and try again.');
        }
        if ((float) $wallet->balance < $retail) {
            return $this->topupError($orderItem, 'Insufficient wallet balance. This top-up costs $'.number_format($retail, 2).' - please add funds and try again.');
        }

        try {
            $order = DB::transaction(function () use ($user, $wallet, $orderItem, $package, $iccid, $netCost, $retail) {
                // 1. Order shell
                $order = Order::create([
                    'user_id' => $user->id,
                    'order_number' => 'RSR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'settlement_currency' => 'USD',
                    'display_currency' => 'USD',
                    'subtotal_amount' => $retail,
                    'markup_amount' => round($retail - $netCost, 2),
                    'total_amount' => $retail,
                    'payment_method' => 'wallet',
                    'payment_status' => PaymentStatus::Paid,
                    'fulfillment_status' => FulfillmentStatus::NotStarted,
                    'order_status' => OrderStatus::Processing,
                    'placed_at' => now(),
                    'metadata' => [
                        'delivery_email' => $user->email,
                        'top_up_of_order_item' => $orderItem->id,
                        'top_up_iccid' => $iccid,
                    ],
                ]);

                // 2. Order item — inherits product/category from the parent
                // eSIM so the fulfilment pipeline, dashboards, and emails all
                // treat it identically to a fresh eSIM purchase.
                $newItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $orderItem->product_id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'category_id' => $orderItem->category_id,
                    'subcategory_id' => $orderItem->subcategory_id,
                    'provider_name' => 'airalo',
                    'provider_offer_id' => 'airalo_'.$package['id'],
                    'product_snapshot' => array_merge((array) $orderItem->product_snapshot, [
                        'is_topup' => true,
                        'top_up_package' => $package,
                    ]),
                    'variant_snapshot' => (array) $orderItem->variant_snapshot,
                    'quantity' => 1,
                    'display_currency' => 'USD',
                    'display_amount' => $retail,
                    'provider_cost_usd' => $netCost,
                    'markup_amount' => round($retail - $netCost, 2),
                    'subtotal_amount' => $retail,
                    'fulfillment_status' => FulfillmentStatus::NotStarted,
                    // Provider reads metadata.parent_iccid to route to /orders/topups.
                    'metadata' => [
                        'parent_iccid' => $iccid,
                        'is_topup' => true,
                    ],
                ]);

                // 3. Debit the wallet
                $this->walletService->debit(
                    wallet: $wallet,
                    amount: $retail,
                    category: TransactionCategory::Purchase,
                    description: "Top-up for eSIM {$iccid}",
                    reference: $order->order_number,
                    idempotencyKey: 'esim-topup-'.$newItem->id,
                    sourceType: 'orders',
                    sourceId: $order->id,
                );

                // 4. Kick off fulfilment (Airalo /orders/topups call)
                FulfillOrderItemJob::dispatch($newItem);

                return $order;
            });
        } catch (WalletOnHoldException|InsufficientBalanceException|MissingTransactionPinException $e) {
            // Customer-friendly: pass the wallet message through verbatim so the
            // user sees e.g. "Your wallet is currently on hold. Please contact
            // support…" instead of the generic "Could not start the top-up".
            return back()->withErrors(['package_id' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('eSIM top-up purchase failed', [
                'user_id' => $user->id,
                'order_item' => $orderItem->id,
                'iccid' => $iccid,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['package_id' => 'Could not start the top-up. Please try again.']);
        }

        return redirect()->route('shop.order', $order->order_number)
            ->with('status', 'Top-up ordered. Your eSIM is being refilled.');
    }

    /**
     * Send the customer back to the top-up page with a friendly, specific
     * message instead of a raw abort() error page, for recoverable states
     * (provider hiccup, package gone, no/low funds). The page renders these
     * from the error bag (shop/esim-topup.blade.php).
     */
    private function topupError(OrderItem $orderItem, string $message): RedirectResponse
    {
        return redirect()
            ->route('dashboard.esim.topup', $orderItem)
            ->withErrors(['package_id' => $message]);
    }

    /**
     * Customers can only top up eSIMs that belong to one of their own
     * fulfilled orders. Belt-and-braces — the route already requires auth.
     */
    private function authorizeAccess(Request $request, OrderItem $orderItem): void
    {
        $orderItem->loadMissing('order');
        abort_if(! $request->user(), 403);
        abort_if($orderItem->order?->user_id !== $request->user()->id, 403);
        abort_if($orderItem->fulfillment_status !== FulfillmentStatus::Fulfilled, 422, 'The eSIM is not active yet.');
    }

    private function extractIccid(OrderItem $orderItem): ?string
    {
        $payload = (array) ($orderItem->fulfillment_payload ?? []);
        $iccid = $payload['iccid'] ?? ($payload['esim']['iccid'] ?? null);

        return is_scalar($iccid) ? (string) $iccid : null;
    }
}
