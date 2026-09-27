{{-- KYC verification modal + launcher (Smile ID hosted Web Integration).

     Opened by the KYC page's "Verify now" button and by money actions that
     return { kyc_required: true, smile: {...} }. launch() hands the minted web
     token to Smile's HOSTED widget (their inline script), which runs the whole
     flow itself: country + ID-type selection (NIN, BVN, passport...), ID-number
     entry, selfie capture, and submission to Smile. On success we record the
     submission (pending state), poll /api/kyc/status until the callback lands,
     then run the caller's onVerified.

     Our shell only anchors the experience (status text, cancel); the widget
     renders its own full-screen overlay above it.

     window.rshopKyc.launch(smilePayload, onVerified) is the single entry point,
     so the KYC page, checkout and wallet funding all reuse it.

     SANDBOX: config keys below follow Smile's documented hosted integration
     (inline/js/v1 script + window.SmileIdentity). Requires real SMILE_* env
     credentials; confirm end-to-end in the Smile sandbox before go-live. --}}
{{-- z-[130]: must sit ABOVE every launcher surface (checkout/funding payment
     modals are z-[100], the funding risk overlay is z-[110]) or the flow opens
     invisibly behind them. --}}
<div id="rshop-kyc-modal" hidden class="fixed inset-0 z-[130] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-zinc-900/60"></div>
    <div class="relative flex w-full max-w-md flex-col overflow-hidden rounded-[12px] bg-[#0c1a36] text-white shadow-2xl">
        <div class="flex items-start justify-between gap-3 px-5 py-4">
            <h2 class="text-lg font-bold">Verify your identity</h2>
            <button type="button" id="rshop-kyc-close" aria-label="Cancel" class="rounded-[10px] p-1.5 text-white/70 hover:bg-white/10">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="px-5 pb-2">
            <p id="rshop-kyc-status" class="py-4 text-center text-sm text-white/75">Preparing secure verification...</p>
        </div>

        <div class="flex items-center justify-end border-t border-white/10 px-5 py-3 text-sm">
            <button type="button" id="rshop-kyc-cancel" class="font-semibold text-white/70 hover:text-white">Cancel</button>
        </div>
    </div>
</div>

<script>
window.rshopKyc = (function () {
    // Smile's hosted Web Integration script: exposes window.SmileIdentity, which
    // opens Smile's own full-screen verification flow. Path order matters:
    // inline/v1/js, per docs.usesmileid.com Web Integration installation.
    const SMILE_SDK_URL = 'https://cdn.smileidentity.com/inline/v1/js/script.min.js';
    let sdkPromise = null;
    let onVerifiedCb = null;

    const modal = () => document.getElementById('rshop-kyc-modal');
    const statusEl = () => document.getElementById('rshop-kyc-status');

    function loadSdk() {
        if (window.SmileIdentity) return Promise.resolve();
        if (sdkPromise) return sdkPromise;
        sdkPromise = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = SMILE_SDK_URL;
            s.async = true;
            s.onload = resolve;
            s.onerror = () => { sdkPromise = null; reject(new Error('smile-sdk-load-failed')); };
            document.head.appendChild(s);
        });
        return sdkPromise;
    }

    function open() {
        modal().removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        modal().setAttribute('hidden', '');
        document.body.style.overflow = '';
        setStatus('Preparing secure verification...');
    }

    function setStatus(text) {
        statusEl().textContent = text;
    }

    // Record that the capture reached Smile, so the KYC page shows an honest
    // "in review" state even if the provider callback is slow. Only called on
    // the widget's success, never optimistically.
    // Resolves true once the server has the account in review. A retry after an
    // earlier rejection only counts a later "rejected" as this attempt's result
    // when the account was moved to review first; otherwise the old rejection
    // would read as the new attempt failing.
    async function markSubmitted() {
        try {
            const res = await fetch('{{ route('api.kyc.submitted') }}', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            return res.ok;
        } catch (e) {
            return false;
        }
    }

    // Wait for Smile's async callback to reach our server and flip kyc_status.
    // Resolves 'verified' | 'rejected' | 'timeout' - a rejection must surface,
    // not leave the customer staring at "confirming" until the clock runs out.
    async function pollUntilVerified(trustRejected = true) {
        setStatus('Confirming your verification, please wait...');
        const started = Date.now();
        while (Date.now() - started < 120000) {
            try {
                const res = await fetch('{{ route('api.kyc.status') }}', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.verified) return 'verified';
                if (trustRejected && data.kyc_status === 'rejected') return 'rejected';
            } catch (e) { /* keep polling */ }
            await new Promise((r) => setTimeout(r, 3000));
        }
        return 'timeout';
    }

    // What the customer sees once a verdict is known (or still is not), then
    // the caller's continuation on success.
    function settle(outcome) {
        if (outcome === 'verified') {
            close();
            if (typeof onVerifiedCb === 'function') onVerifiedCb();
            return;
        }

        if (outcome === 'rejected') {
            // Surface the rejection, then reload so the page shows its
            // rejected state (Try again on the KYC page).
            setStatus('Your verification was not successful. Please review your details and try again.');
            setTimeout(() => { close(); window.location.reload(); }, 2500);
            return;
        }

        setStatus('Your verification is still being reviewed. We will email you as soon as it is done.');
    }

    async function launch(smile, onVerified) {
        onVerifiedCb = onVerified;
        open();

        // Already submitted and under review: nothing new to launch, just wait
        // for the verdict and continue the payment if it passes.
        if (smile && smile.in_review) {
            settle(await pollUntilVerified());
            return;
        }

        if (! smile || ! smile.token) {
            setStatus('Verification is currently unavailable, try again later or contact support.');
            return;
        }

        try {
            await loadSdk();
        } catch (e) {
            setStatus('Could not load the verification tool. Please check your connection and try again.');
            return;
        }

        setStatus('Follow the steps in the verification window...');

        // --- Smile hosted widget (CONFIRM against sandbox) -------------------
        const smileConfig = {
            token: smile.token,
            product: smile.product || 'doc_verification',
            callback_url: smile.callback_url || '',
            environment: smile.environment === 'production' ? 'live' : 'sandbox',
            partner_details: {
                partner_id: String(smile.partner_id || ''),
                name: 'RshopRefill',
                logo_url: '{{ asset('assets/logo.webp').'?v=4' }}',
                policy_url: '{{ route('shop.privacy') }}',
                theme_color: '#2563eb',
            },
            onSuccess: async () => {
                const recorded = await markSubmitted();
                const outcome = await pollUntilVerified(recorded);

                // Timeout: verdict still in flight; the KYC page reflects it
                // and the outcome email follows.
                if (outcome === 'timeout') {
                    close();
                    return;
                }

                settle(outcome);
            },
            onClose: () => close(),
            onError: () => {
                setStatus('Verification could not be completed. Please try again.');
            },
        };

        // Optional country -> ID-type pinning (config services.smileid.id_selection).
        // Only set once the listed ID types are ENABLED on the Smile account; it
        // also de-duplicates the widget's country menu.
        const ID_SELECTION = @json(config('services.smileid.id_selection'));
        if (ID_SELECTION) {
            smileConfig.id_selection = ID_SELECTION;
        }

        window.SmileIdentity(smileConfig);
        // --------------------------------------------------------------------
    }

    // closest() — not e.target.id — because the X button's click lands on the
    // SVG inside it, never the button element itself.
    document.addEventListener('click', (e) => {
        if (e.target && e.target.closest && e.target.closest('#rshop-kyc-close, #rshop-kyc-cancel')) {
            close();
        }
    });

    return { launch, close };
})();
</script>
