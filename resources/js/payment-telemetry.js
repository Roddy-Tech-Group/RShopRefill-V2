/**
 * Client-side payment observability.
 *
 * Two channels, mirroring the server split:
 *  - recordFailure(sessionId, code, context): TERMINAL — fails the payment
 *    session server-side (customer gave up, or payment cannot proceed).
 *  - recordSignal(sessionId, code, context): environmental signal — recorded
 *    for analytics only, never touches the payment state machine.
 *
 * Codes are enumerated (never free text) and kept in sync with
 * FailureReasonClassifier::CLIENT_CODES on the server:
 *   terminal: popup_closed, popup_blocked, sdk_load_failed, page_abandoned
 *   signals:  js_exception, network_offline, network_timeout, slow_network
 */
window.PaymentTelemetry = (function () {
    const FAILURE_URL = '/api/payment-sessions/telemetry/record-failure';
    const SIGNAL_URL = '/api/payment-sessions/telemetry/record-signal';

    function post(url, payload, useBeacon = false) {
        const body = JSON.stringify(payload);

        // sendBeacon survives page unload (fetch gets cancelled), which is
        // the whole point of the page_abandoned event.
        if (useBeacon && navigator.sendBeacon) {
            try {
                navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
                return;
            } catch (e) { /* fall through to fetch */ }
        }

        fetch(url, {
            method: 'POST',
            keepalive: useBeacon,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body,
        }).catch(err => console.error('Payment telemetry failed', err));
    }

    function recordFailure(sessionId, code, context = {}, useBeacon = false) {
        if (!sessionId) return;
        post(FAILURE_URL, { session_id: sessionId, reason: code, context: context }, useBeacon);
    }

    function recordSignal(sessionId, code, context = {}) {
        if (!sessionId) return;
        post(SIGNAL_URL, { session_id: sessionId, code: code, context: context });
    }

    /**
     * Watch the environment while a payment is in flight. Call when handing
     * off to the provider (popup opened); call the returned disarm() the
     * moment the flow resolves (callback fired or popup closed) so a later
     * tab close isn't misreported as an abandoned payment.
     */
    function armSession(sessionId, context = {}) {
        if (!sessionId) return function () {};

        let exceptionsSent = 0;

        const onError = function (event) {
            if (exceptionsSent >= 3) return; // don't flood on error loops
            exceptionsSent++;
            recordSignal(sessionId, 'js_exception', Object.assign({}, context, {
                message: String(event.reason || event.message || 'unknown').slice(0, 200),
            }));
        };
        const onOffline = function () {
            recordSignal(sessionId, 'network_offline', context);
        };
        const onPageHide = function () {
            // Real navigation away mid-payment — terminal abandonment.
            recordFailure(sessionId, 'page_abandoned', context, true);
        };

        window.addEventListener('error', onError);
        window.addEventListener('unhandledrejection', onError);
        window.addEventListener('offline', onOffline);
        window.addEventListener('pagehide', onPageHide);

        // One-shot connection-quality sample (Network Information API,
        // Chromium only — silently unavailable elsewhere).
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection && (connection.effectiveType === '2g' || connection.effectiveType === 'slow-2g')) {
            recordSignal(sessionId, 'slow_network', Object.assign({}, context, {
                effective_type: connection.effectiveType,
            }));
        }

        return function disarm() {
            window.removeEventListener('error', onError);
            window.removeEventListener('unhandledrejection', onError);
            window.removeEventListener('offline', onOffline);
            window.removeEventListener('pagehide', onPageHide);
        };
    }

    return {
        recordFailure: recordFailure,
        recordSignal: recordSignal,
        armSession: armSession,
    };
})();
