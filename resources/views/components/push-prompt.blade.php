@feature('push_notifications')
{{-- Push is opt-out: notifications are ON by default (account notification
     settings toggle them off). This headless component auto-subscribes the
     browser silently once notification permission is granted, and fires the
     browser's own one-time permission prompt for first-time visitors - no
     custom "Enable" card. Browsers that have denied, or don't support push, are
     left alone. It lives in the dashboard layout, so it only runs for signed-in
     users (the /push/subscribe endpoint requires auth).

     The flags below are the careful part. A browser asks for notification
     permission once; there is no second prompt. This used to mark the ask as
     spent BEFORE attempting it, so any failure - a missing VAPID key, a
     service worker that never registered, a POST that 401'd - permanently cost
     that device its only chance, silently. Now the mark is written from the
     OUTCOME: only when the browser actually answered. Our own failures leave
     the flag clear so a later visit tries again. --}}
<div
    x-data
    x-init="
        if (! window.RshopPush) return;
        if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || typeof Notification === 'undefined') return;

        // Respect an explicit block. Nothing we do gets past it.
        if (Notification.permission === 'denied') return;

        if (Notification.permission === 'granted') {
            // Already allowed - make sure this device is registered. Once per
            // session so we don't POST on every page load, and the marker is
            // written only on success, so a failed sync retries next load
            // rather than waiting for a new session.
            if (sessionStorage.getItem('pushSynced')) return;

            window.RshopPush.subscribe().then((result) => {
                if (result === 'ok') sessionStorage.setItem('pushSynced', '1');
            });

            return;
        }

        // Never asked. Trigger the browser's own prompt, and remember it only
        // if the browser genuinely put the question to them: 'ok' means granted
        // and registered, 'denied' means they said no. Every other result is
        // our side failing before the prompt could mean anything.
        if (localStorage.getItem('pushAsked')) return;

        window.RshopPush.subscribe().then((result) => {
            if (result === 'ok' || result === 'denied') {
                localStorage.setItem('pushAsked', '1');
            }
        });
    "
></div>
@endfeature
