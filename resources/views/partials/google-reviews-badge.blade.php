{{--
    Google Customer Reviews - seller-rating badge (floating, storefront-wide).

    Shows the site's Google seller rating and links to the reviews. Renders
    "no rating available" until Google has collected enough surveys via the
    order-confirmation opt-in (see shop/_google-customer-reviews). Off until
    services.google_reviews.merchant_id is set, so dev/staging render nothing.
--}}
@php
    $gcrMerchantId = config('services.google_reviews.merchant_id');
@endphp

@if ($gcrMerchantId && config('services.google_reviews.badge_enabled', true))
    <script id="merchantWidgetScript" src="https://www.gstatic.com/shopping/merchant/merchantwidget.js" defer></script>
    <script>
      merchantWidgetScript.addEventListener('load', function () {
        merchantwidget.start({
          "merchant_id": {{ (int) $gcrMerchantId }},
          "position": @js(config('services.google_reviews.badge_position', 'BOTTOM_LEFT'))
        });
      });
    </script>
@endif
