@php
    // Marketing / analytics tags, driven by the admin SEO settings first
    // (System Settings -> SEO) and falling back to env. Each renders nothing
    // until its ID is set, and none load on the admin panel - internal staff
    // sessions must not pollute customer analytics.
    $isAdminArea = request()->is('admin*');

    $gaId = \App\Models\SiteSetting::get('seo.google_analytics_id') ?: config('services.google.analytics_id');
    $gtmId = \App\Models\SiteSetting::get('seo.google_tag_manager_id');
    $fbPixelId = \App\Models\SiteSetting::get('seo.facebook_pixel_id');
@endphp

@unless ($isAdminArea)
    @if ($gaId || $gtmId || $fbPixelId)
        {{-- Consent-gated loader. The Cookie Policy promises visitors control
             over non-essential cookies, so this is where that promise is
             enforced: no analytics or advertising tag loads until the cookie
             banner is ACCEPTED (localStorage.cookie_consent === 'accepted').
             Accepting fires `cookie-consent-accepted`, which loads the tags
             immediately without a reload; declined or undecided visitors load
             nothing at all. --}}
        <script>
            (function () {
                var loaded = false;
                var ids = { ga: @json($gaId), gtm: @json($gtmId), pixel: @json($fbPixelId) };

                function loadTags() {
                    if (loaded) { return; }
                    loaded = true;

                    if (ids.ga) {
                        var s = document.createElement('script');
                        s.async = true;
                        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ids.ga);
                        document.head.appendChild(s);
                        window.dataLayer = window.dataLayer || [];
                        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
                        window.gtag('js', new Date());
                        window.gtag('config', ids.ga);
                    }

                    if (ids.gtm) {
                        (function (w, d, s, l, i) {
                            w[l] = w[l] || []; w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
                            var f = d.getElementsByTagName(s)[0], j = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : '';
                            j.async = true; j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                            f.parentNode.insertBefore(j, f);
                        })(window, document, 'script', 'dataLayer', ids.gtm);
                    }

                    if (ids.pixel) {
                        !function (f, b, e, v, n, t, s) {
                            if (f.fbq) return; n = f.fbq = function () {
                                n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments)
                            };
                            if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
                            t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0];
                            s.parentNode.insertBefore(t, s)
                        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
                        window.fbq('init', ids.pixel);
                        window.fbq('track', 'PageView');
                    }
                }

                var consent = null;
                try { consent = localStorage.getItem('cookie_consent'); } catch (e) {}

                if (consent === 'accepted') {
                    loadTags();
                }

                window.addEventListener('cookie-consent-accepted', loadTags);
            })();
        </script>
    @endif
@endunless
