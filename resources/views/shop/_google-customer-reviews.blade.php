{{--
    Google Customer Reviews - opt-in survey snippet.

    NOT CURRENTLY INCLUDED ANYWHERE. It used to render on the completed-order
    confirmation page, where the Google survey overlay landed on top of the
    buyer's codes the moment their order completed - the wrong moment to
    interrupt someone. The snippet is kept intact (and tested) so re-enabling it
    is a one-line @include on shop/order.blade.php; the rest of the Google
    Customer Reviews integration, including the seller-rating badge, is untouched.

    After purchase Google shows an opt-in so the buyer can be surveyed later;
    their rating then rolls up into the seller rating shown by the badge (see
    partials/google-reviews-badge).

    Off until services.google_reviews.merchant_id is set, so dev/staging render
    nothing. GTINs are intentionally omitted: this catalog ships no product
    identifiers (GoogleMerchantFeedController emits identifier_exists = no).

    Expects: $order (App\Models\Order) with its items relation loaded.
--}}
@php
    $gcrMerchantId = config('services.google_reviews.merchant_id');
@endphp

@if ($gcrMerchantId)
    @php
        // Survey recipient: the address the codes were delivered to, falling back
        // to the buyer's account email.
        $gcrEmail = $order->metadata['delivery_email'] ?? auth()->user()?->email;

        // delivery_country must be a 2-letter ISO code. A digital order has no
        // shipping address, so derive it: the buyer's declared account country
        // first (stored as a full display name or a code), then the market the
        // purchased product belongs to, then a safe default.
        $gcrNameToCode = config('countries.codes', []);
        $gcrRawCountry = auth()->user()?->country;
        $gcrCountry = null;
        if ($gcrRawCountry) {
            $gcrCountry = strlen($gcrRawCountry) === 2
                ? strtoupper($gcrRawCountry)
                : ($gcrNameToCode[$gcrRawCountry] ?? null);
        }
        $gcrCountry = $gcrCountry
            ?: strtoupper((string) ($order->items->first()?->product_snapshot['country_code'] ?? ''));
        $gcrCountry = $gcrCountry ?: 'US';

        // Instant digital fulfilment: "estimated delivery" is the moment the order
        // completed (fallbacks keep it valid for legacy rows).
        $gcrDeliveryDate = ($order->completed_at ?? $order->placed_at ?? now())->format('Y-m-d');
    @endphp

    <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
    <script>
      window.renderOptIn = function () {
        window.gapi.load('surveyoptin', function () {
          window.gapi.surveyoptin.render({
            "merchant_id": {{ (int) $gcrMerchantId }},
            "order_id": @js($order->order_number),
            "email": @js($gcrEmail),
            "delivery_country": @js($gcrCountry),
            "estimated_delivery_date": @js($gcrDeliveryDate)
          });
        });
      };
    </script>
@endif
