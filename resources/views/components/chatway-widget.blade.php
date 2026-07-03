@php
    // Chatway live chat. Renders nothing until CHATWAY_WIDGET_ID is set in
    // the environment, so local/dev and any deploy without the key stay clean.
    $chatwayWidgetId = config('services.chatway.widget_id');
@endphp

@if ($chatwayWidgetId)
    <script id="chatway" async="true" src="https://cdn.chatway.app/widget.js?id={{ $chatwayWidgetId }}"></script>
    <script>
        // Chatway's loader mounts a div.chatway--container onto <body> about
        // one second after the script runs. wire:navigate swaps the <body>,
        // which silently deletes that container. Keep a reference to the real
        // container and re-attach the SAME node after each SPA navigation.
        // Never remove or re-inject the loader script: the mount is delayed,
        // so "nothing mounted yet" is not "broken" - acting on it mid-flight
        // is what kills the widget.
        (function () {
            if (window.__chatwayKeepAlive) {
                return;
            }
            window.__chatwayKeepAlive = true;

            let container = null;

            // The live chat is a storefront feature and must never appear on the
            // dashboard. wire:navigate persists DOM between pages, so on each
            // navigation we re-attach + show the widget on storefront pages and
            // hide it on any /dashboard page.
            const onDashboard = () => window.location.pathname.startsWith('/dashboard');

            const apply = () => {
                const el = document.querySelector('.chatway--container');
                if (el) {
                    container = el;
                }
                if (! container) {
                    return;
                }
                if (onDashboard()) {
                    container.style.display = 'none';
                } else {
                    container.style.display = '';
                    if (! document.body.contains(container)) {
                        document.body.appendChild(container);
                    }
                }
            };

            // Grab the container once the loader mounts it (~1s, slower on bad
            // networks). Poll gently, stop once captured or after 30s.
            const captureTimer = setInterval(() => {
                apply();
                if (container) {
                    clearInterval(captureTimer);
                }
            }, 750);
            setTimeout(() => clearInterval(captureTimer), 30000);

            document.addEventListener('livewire:navigated', () => {
                // Give the swap a moment to settle, then show/hide for the page
                // we landed on. Re-attaching the same node keeps all of Chatway's
                // listeners; the iframe reloads itself.
                setTimeout(apply, 400);
            });
        })();
    </script>
@endif
