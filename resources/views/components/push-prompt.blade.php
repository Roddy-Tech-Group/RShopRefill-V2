@feature('push_notifications')
{{-- Push is opt-out: notifications are ON by default (account notification
     settings toggle them off). This headless component auto-subscribes the
     browser silently once notification permission is granted, and fires the
     browser's own one-time permission prompt for first-time visitors — no custom
     "Enable" card. Browsers that have denied, or don't support push, are left
     alone. It lives in the dashboard layout, so it only runs for signed-in users
     (the /push/subscribe endpoint requires auth). --}}
<div
    x-data
    x-init="
        if (! window.RshopPush) return;
        if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || typeof Notification === 'undefined') return;

        // Respect an explicit block.
        if (Notification.permission === 'denied') return;

        if (Notification.permission === 'granted') {
            // Already allowed — make sure this device is registered. Once per
            // session so we don't POST on every page load.
            if (sessionStorage.getItem('pushSynced')) return;
            sessionStorage.setItem('pushSynced', '1');
            window.RshopPush.subscribe();
            return;
        }

        // Never asked: trigger the browser's own permission prompt a single time,
        // then subscribe on grant. Remembered so we never nag on later loads.
        if (localStorage.getItem('pushAsked')) return;
        localStorage.setItem('pushAsked', '1');
        window.RshopPush.subscribe();
    "
></div>
@endfeature
