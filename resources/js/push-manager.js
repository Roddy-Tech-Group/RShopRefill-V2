/**
 * Browser push subscription.
 *
 * The order of the checks here is the whole point. This used to ask for
 * notification permission first and only then look for the VAPID key, the
 * service worker and a server that would accept the subscription. Permission is
 * a one-shot: a browser asks once, and a customer who grants it to a flow that
 * then fails has spent it for nothing, because a second prompt is not offered.
 *
 * So everything that can fail without the customer's involvement is settled
 * BEFORE the prompt appears, and every exit says which kind it was. Callers use
 * that to decide whether asking again later is worth anything: 'denied' and
 * 'ok' mean the browser has answered and the question is closed, while
 * 'unsupported', 'no-key', 'no-worker' and 'failed' are our problem, not a
 * decision, and should be retried on a later visit.
 */

/** How long to wait for a service worker registration before giving up. */
const WORKER_TIMEOUT_MS = 10000;

export const RshopPush = {
    /**
     * @returns {Promise<'ok'|'denied'|'unsupported'|'no-key'|'no-worker'|'failed'>}
     */
    async subscribe() {
        if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || typeof Notification === 'undefined') {
            return 'unsupported';
        }

        // No key, no subscription possible. Checked before the prompt so a
        // misconfigured deploy cannot cost every visitor their one ask.
        const vapidPublicKey = document.querySelector('meta[name="vapid-public-key"]')?.content;
        if (! vapidPublicKey) {
            console.error('VAPID public key meta tag is missing or empty.');
            return 'no-key';
        }

        // navigator.serviceWorker.ready never resolves until something is
        // registered, so a failed registration would otherwise hang this call
        // forever with the prompt already answered.
        const registration = await this.readyWorker();
        if (! registration) {
            return 'no-worker';
        }

        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return 'denied';
            }

            // An existing subscription is reused rather than replaced: the
            // endpoint is stable, and re-subscribing would orphan the row the
            // server already holds for this device.
            const subscription = await registration.pushManager.getSubscription()
                || await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(vapidPublicKey),
                });

            return (await this.sync(subscription)) ? 'ok' : 'failed';
        } catch (error) {
            console.error('Failed to subscribe:', error);
            return 'failed';
        }
    },

    /**
     * Hand the subscription to the server. Separate from subscribe() because a
     * browser can hold a subscription the server has lost (a pruned row, a
     * restored database), and re-POSTing a known endpoint is how that heals.
     *
     * @returns {Promise<boolean>} whether the server accepted it
     */
    async sync(subscription) {
        try {
            const res = await fetch('/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON()),
            });

            return res.ok;
        } catch (error) {
            console.error('Failed to register the subscription:', error);
            return false;
        }
    },

    /**
     * The active service worker registration, or null if none arrives in time.
     */
    async readyWorker() {
        try {
            return await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((resolve) => setTimeout(() => resolve(null), WORKER_TIMEOUT_MS)),
            ]);
        } catch (_) {
            return null;
        }
    },

    async unsubscribe() {
        if (! ('serviceWorker' in navigator) || ! ('PushManager' in window)) {
            return false;
        }

        try {
            const registration = await this.readyWorker();
            if (! registration) {
                return false;
            }

            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                // Tell the server first. Dropping it locally before the server
                // knows would leave a row we can no longer name the endpoint of.
                await fetch('/push/unsubscribe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                }).catch(() => {});

                await subscription.unsubscribe();
            }

            return true;
        } catch (error) {
            console.error('Failed to unsubscribe:', error);
            return false;
        }
    },

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }
};

window.RshopPush = RshopPush;
