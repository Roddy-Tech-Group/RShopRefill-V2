<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-[#eff6ff] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="relative z-10 w-full max-w-md">
        {{-- One Alpine scope wraps the verify form, the resend form, the log-out
             form and the footer row, so the footer buttons can read the code
             state while still submitting their own form by id. Nested forms are
             invalid HTML, so the forms are siblings and the buttons point at
             them with form="…". --}}
        <div
            class="overflow-hidden rounded-[12px] border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#122343]"
            x-data="{
                digits: ['', '', '', '', '', ''],
                loading: false,
                resending: false,
                {{-- Latches the moment the code is sent, so the sixth digit fires
                     exactly one submit. Without it, any later input event - a
                     stray keystroke, an autofill re-run - posts the code twice
                     and burns a second attempt against the throttle. --}}
                submitted: false,
                {{-- Both clocks start from what the server measured; nothing here invents a time. --}}
                expiresIn: @js($expiresIn),
                cooldown: {{ (int) ($cooldown ?? 0) }},
                get code() { return this.digits.join(''); },
                get complete() { return /^[0-9]{6}$/.test(this.code); },
                get expired() { return this.expiresIn !== null && this.expiresIn <= 0; },
                get clock() {
                    const left = Math.max(0, this.expiresIn ?? 0);
                    return `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
                },
                get cooldownText() {
                    if (this.cooldown < 60) return `${this.cooldown}s`;
                    const m = Math.floor(this.cooldown / 60), s = this.cooldown % 60;
                    return s > 0 ? `${m}m ${s}s` : `${m}m`;
                },
                init() {
                    const tick = setInterval(() => {
                        if (this.expiresIn !== null && this.expiresIn > 0) this.expiresIn--;
                        if (this.cooldown > 0) this.cooldown--;
                        if ((this.expiresIn === null || this.expiresIn <= 0) && this.cooldown <= 0) clearInterval(tick);
                    }, 1000);
                    this.$nextTick(() => this.box(0)?.focus());
                },
                box(i) { return this.$refs['box' + i]; },
                onInput(i, event) {
                    const value = (event.target.value || '').replace(/\D/g, '');
                    if (value.length > 1) { this.fill(value, i); return; }
                    this.digits[i] = value;
                    event.target.value = value;
                    if (value && i < 5) this.box(i + 1).focus();
                    this.autoSubmit();
                },
                onKeydown(i, event) {
                    if (event.key === 'Backspace' && ! this.digits[i] && i > 0) {
                        this.digits[i - 1] = '';
                        this.box(i - 1).focus();
                        event.preventDefault();
                    }
                    if (event.key === 'ArrowLeft' && i > 0) this.box(i - 1).focus();
                    if (event.key === 'ArrowRight' && i < 5) this.box(i + 1).focus();
                },
                onPaste(event) {
                    const pasted = (event.clipboardData?.getData('text') || '').replace(/\D/g, '');
                    if (pasted) { event.preventDefault(); this.fill(pasted, 0); }
                },
                {{-- Writes BOTH the state and the input elements. The boxes are
                     uncontrolled - only :class is bound, not :value - so setting
                     digits alone flipped them to their filled colour while they
                     still looked empty, which is what a pasted code used to do.
                     Typing never hit this because onInput assigns target.value
                     itself. Reached by paste, and by onInput when a browser or
                     password manager drops the whole code into one box. --}}
                fill(value, from) {
                    const chars = value.slice(0, 6 - from).split('');
                    chars.forEach((c, k) => {
                        this.digits[from + k] = c;
                        const el = this.box(from + k);
                        if (el) el.value = c;
                    });
                    this.box(Math.min(5, from + chars.length)).focus();
                    this.autoSubmit();
                },
                {{-- Sends the code the moment the sixth digit lands, typed or
                     pasted, so nobody has to reach for the button after already
                     entering everything the form needs.

                     Guarded on `submitted` (one send per page), `loading` (a send
                     already in flight) and `expired` (a dead code would spend an
                     attempt for nothing). The short delay is deliberate: it lets
                     the last box paint its filled state, so the form is visibly
                     complete before it disappears rather than vanishing under the
                     keystroke. requestSubmit, not submit, so the form's own
                     @submit guard still runs. --}}
                autoSubmit() {
                    if (this.submitted || this.loading || this.expired || ! this.complete) return;
                    this.submitted = true;
                    setTimeout(() => {
                        const form = document.getElementById('verify-form');
                        if (! form) return;
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    }, 180);
                },
            }"
        >
            <div class="border-b border-zinc-100 px-6 py-5 dark:border-white/10 sm:px-8">
                <h1 class="text-xl font-extrabold tracking-tight text-zinc-900 dark:text-white">Verify Your Device</h1>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">One-time password</p>
            </div>

            <div class="px-6 py-6 sm:px-8">

                @if (session('status'))
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                         x-transition:leave="transition ease-in duration-300"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="mb-4 rounded-[12px] bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-400">
                        {{ session('status') }}
                    </div>
                @endif

                @error('resend')
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                         x-transition:leave="transition ease-in duration-300"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="mb-4 rounded-[12px] bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                        {{ $message }}
                    </div>
                @enderror

                @error('error')
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                         x-transition:leave="transition ease-in duration-300"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="mb-4 rounded-[12px] bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                {{-- Where the code went. The address is masked: this page is
                     reachable on a device that is not yet trusted. --}}
                <div class="flex items-start gap-3 rounded-[12px] bg-blue-50 px-4 py-3 ring-1 ring-blue-200 dark:bg-white/5 dark:ring-white/10">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
                    </svg>
                    <div class="min-w-0 text-sm">
                        <p class="font-semibold text-zinc-900 dark:text-white">Enter Verification Code</p>
                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-300">Please enter the 6-digit code sent to <span class="font-semibold text-zinc-900 dark:text-white">{{ $maskedEmail }}</span></p>
                    </div>
                </div>

                {{-- The six boxes. Each holds one digit; typing advances, backspace
                     retreats, and a pasted code fills them all. The real field is
                     the hidden input assembled from the boxes, so the server keeps
                     reading the same `otp` it always did. The first box carries
                     autocomplete="one-time-code" so a phone can offer the code from
                     the mail. --}}
                <form
                    id="verify-form"
                    method="POST"
                    action="{{ route('security.verify-device.verify') }}"
                    class="mt-6"
                    @submit="if (! complete || expired) { $event.preventDefault(); return; } loading = true"
                >
                    @csrf
                    <input type="hidden" name="otp" :value="code">

                    <div class="flex justify-between gap-2" @paste="onPaste($event)">
                        @for ($i = 0; $i < 6; $i++)
                            <input
                                id="otp-box-{{ $i }}"
                                x-ref="box{{ $i }}"
                                type="text"
                                inputmode="numeric"
                                maxlength="1"
                                @if ($i === 0) autocomplete="one-time-code" @else autocomplete="off" @endif
                                aria-label="Digit {{ $i + 1 }} of 6"
                                @input="onInput({{ $i }}, $event)"
                                @keydown="onKeydown({{ $i }}, $event)"
                                @focus="$event.target.select()"
                                {{-- A filled box takes the same blue as the submit
                                     button below it, so the code you have typed and
                                     the action it feeds read as one control. It was
                                     #0c1337, a near-black navy, which landed as six
                                     heavy dark blocks on a white card and did not
                                     match anything else on the page - dark mode was
                                     already blue here, so light was the odd one. --}}
                                :class="digits[{{ $i }}]
                                    ? 'border-blue-600 bg-blue-600 text-white dark:border-blue-500 dark:bg-blue-600'
                                    : 'border-zinc-200 bg-zinc-50 text-zinc-900 dark:border-white/15 dark:bg-white/5 dark:text-white'"
                                class="h-14 w-full max-w-[3.25rem] rounded-[12px] border-2 text-center text-2xl font-bold outline-none transition-colors focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
                            >
                        @endfor
                    </div>

                    @error('otp')
                        <p class="mt-3 text-center text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    {{-- The code's real remaining life. Hidden when the server had
                         nothing to measure it against. --}}
                    <div
                        x-show="expiresIn !== null"
                        x-cloak
                        :class="expired
                            ? 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-500/30'
                            : 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-500/30'"
                        class="mt-4 flex items-center justify-center gap-2 rounded-[12px] px-4 py-2.5 text-sm ring-1"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                        <span x-show="! expired">Code expires in <strong class="font-bold" x-text="clock"></strong></span>
                        <span x-show="expired" x-cloak>This code has expired. Request a new one below.</span>
                    </div>
                </form>

                {{-- Resend. The wait is the server's throttle, ticking down here. --}}
                <form
                    id="resend-form"
                    method="POST"
                    action="{{ route('security.verify-device.send') }}"
                    class="mt-4"
                    @submit="if (cooldown > 0) { $event.preventDefault(); return; } resending = true"
                >
                    @csrf

                    <div x-show="cooldown > 0" class="flex items-center justify-center gap-2 rounded-[12px] bg-zinc-50 px-4 py-2.5 text-sm text-zinc-700 ring-1 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        </svg>
                        <span>Resend available in <strong class="font-bold" x-text="cooldownText"></strong></span>
                    </div>

                    <button
                        type="submit"
                        :disabled="resending || cooldown > 0"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-[12px] bg-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-800 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15"
                    >
                        <svg class="h-4 w-4 shrink-0" :class="resending ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                        </svg>
                        <span x-text="resending ? 'Sending...' : 'Resend Code'">Resend Code</span>
                    </button>
                </form>

                <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
                    @csrf
                </form>

                {{-- Cancel logs out; continue verifies. Neither sits inside the
                     other's form: each names its own by id. --}}
                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button
                        type="submit"
                        form="logout-form"
                        class="flex items-center justify-center rounded-[12px] bg-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-800 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="verify-form"
                        :disabled="loading || ! complete || expired"
                        class="flex items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-[#122343]"
                    >
                        <svg x-show="loading" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="loading ? 'Verifying...' : 'Verify & Continue'">Verify &amp; Continue</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{--
    No-Alpine fallback for the code boxes.

    Everything above is an Alpine island, including `<input name="otp" :value="code">`
    - the field that actually posts. If Alpine does not boot, that binding never
    runs, so the form submits an EMPTY code no matter what is typed, and the boxes
    stop advancing and stop accepting a paste. On an auth step that is the
    difference between signing in and being stuck, and it fails silently: no error,
    just a form that will not work.

    Alpine arrives as a module through @vite, so anything that stops that request -
    a dev server on the wrong interface, a blocked or cached bundle, an unrelated
    script error earlier in the page - takes this page down with it.

    This is plain, non-module JS that always parses, and it checks for Alpine
    before doing anything. If Alpine booted, Alpine owns the form and this exits;
    exactly one of the two is ever active, so they cannot fight over focus or
    double-advance.
--}}
<script>
(function () {
    function wireFallback() {
        if (typeof window.Alpine !== 'undefined') {
            return; // Alpine is driving; leave it alone.
        }

        var boxes = [];
        for (var i = 0; i < 6; i++) {
            var el = document.getElementById('otp-box-' + i);
            if (! el) { return; }
            boxes.push(el);
        }

        var form = document.getElementById('verify-form');
        var hidden = form && form.querySelector('input[name="otp"]');
        if (! hidden) { return; }

        var submitted = false;

        function sync() {
            hidden.value = boxes.map(function (b) { return b.value; }).join('');
            autoSubmit();
        }

        // Same one-shot auto-send as the Alpine path, so losing Alpine costs the
        // styling and the countdown but never the behaviour.
        function autoSubmit() {
            if (submitted || ! /^[0-9]{6}$/.test(hidden.value)) { return; }
            submitted = true;
            setTimeout(function () {
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }, 180);
        }

        function fillFrom(text, from) {
            var chars = String(text).replace(/\D/g, '').slice(0, 6 - from).split('');
            chars.forEach(function (c, k) { boxes[from + k].value = c; });
            boxes[Math.min(5, from + chars.length)].focus();
            sync();
        }

        boxes.forEach(function (box, i) {
            box.addEventListener('input', function () {
                var value = (box.value || '').replace(/\D/g, '');
                if (value.length > 1) { fillFrom(value, i); return; }
                box.value = value;
                if (value && i < 5) { boxes[i + 1].focus(); }
                sync();
            });

            box.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && ! box.value && i > 0) {
                    boxes[i - 1].value = '';
                    boxes[i - 1].focus();
                    sync();
                    event.preventDefault();
                }
                if (event.key === 'ArrowLeft' && i > 0) { boxes[i - 1].focus(); }
                if (event.key === 'ArrowRight' && i < 5) { boxes[i + 1].focus(); }
            });

            box.addEventListener('paste', function (event) {
                var data = (event.clipboardData || window.clipboardData);
                var text = data && data.getData('text');
                if (text) { event.preventDefault(); fillFrom(text, 0); }
            });

            box.addEventListener('focus', function () { box.select(); });
        });

        boxes[0].focus();
    }

    // Alpine loads as a deferred module, so it is not there at parse time. Wait
    // for load, then give it a beat before deciding it is absent.
    if (document.readyState === 'complete') {
        setTimeout(wireFallback, 400);
    } else {
        window.addEventListener('load', function () { setTimeout(wireFallback, 400); });
    }
})();
</script>

</body>
</html>
