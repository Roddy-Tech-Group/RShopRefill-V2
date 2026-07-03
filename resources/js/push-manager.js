export const RshopPush = {
    async subscribe() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push messaging is not supported.');
            return false;
        }

        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return false;
            }

            const registration = await navigator.serviceWorker.ready;
            const vapidPublicKey = document.querySelector('meta[name="vapid-public-key"]')?.content;
            
            if (!vapidPublicKey) {
                console.error('VAPID public key meta tag not found.');
                return false;
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(vapidPublicKey)
            });

            // Send to server
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const res = await fetch('/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON()),
            });
            
            return res.ok;
        } catch (error) {
            console.error('Failed to subscribe:', error);
            return false;
        }
    },

    async unsubscribe() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (subscription) {
                await subscription.unsubscribe();
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch('/push/unsubscribe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
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
