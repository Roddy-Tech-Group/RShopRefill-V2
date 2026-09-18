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
                fill(value, from) {
                    const chars = value.slice(0, 6 - from).split('');
                    chars.forEach((c, k) => { this.digits[from + k] = c; });
                    this.box(Math.min(5, from + chars.length)).focus();
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
                                :class="digits[{{ $i }}]
                                    ? 'border-[#0c1337] bg-[#0c1337] text-white dark:border-blue-500 dark:bg-blue-600'
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

</body>
</html>
