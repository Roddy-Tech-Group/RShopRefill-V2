<x-layouts.auth.centered>
    <x-slot:title>Two-Factor Authentication</x-slot:title>

    <div class="flex flex-col sm:flex-1">
        {{-- Centered form --}}
        <div class="mx-auto flex w-full max-w-md flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

            {{-- Admin chip --}}
            <div class="flex justify-center">
                <span class="inline-flex items-center gap-1.5 rounded-[6px] bg-blue-300 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-black dark:bg-white/10">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Security Check
                </span>
            </div>

            {{-- Heading --}}
            <h1 class="mt-4 text-center text-3xl font-bold tracking-tight text-zinc-900">Enter your code</h1>
            <p class="mt-2 text-center text-base text-zinc-600">
                @if ($hasTotp)
                    Open your Authenticator app and enter the 6-digit code.
                @else
                    We've sent a 6-digit code to your admin email address.
                @endif
            </p>

            {{-- Status Toast --}}
            @if (session('status'))
            <div 
                x-data="{ show: true }" 
                x-init="setTimeout(() => show = false, 4000)"
                x-show="show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform scale-90 translate-y-4"
                x-transition:enter-end="opacity-100 transform scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 transform scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 transform scale-90 translate-y-4"
                class="fixed bottom-4 right-4 z-50 flex items-center gap-3 rounded-[12px] bg-zinc-900 px-4 py-3 text-white shadow-xl sm:bottom-6 sm:right-6"
                style="display: none;"
            >
                <svg class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span class="text-sm font-medium">{{ session('status') }}</span>
                <button @click="show = false" type="button" class="ml-2 text-zinc-400 hover:text-white focus:outline-none">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            @endif

            {{-- Code form --}}
            <form x-data="{ submitting: false }" @submit="submitting = true" method="POST" action="{{ route('admin.2fa.verify') }}" class="mt-6 flex flex-col gap-3 sm:mt-8 sm:gap-4">
                @csrf

                {{-- Code --}}
                <div>
                    <label for="code" class="mb-1.5 block text-base font-medium text-zinc-700">Authentication Code</label>
                    <div class="relative">
                        <input
                            id="code"
                            name="code"
                            type="text"
                            inputmode="numeric"
                            required
                            autofocus
                            autocomplete="one-time-code"
                            placeholder="Enter 6-digit code"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white py-3 px-4 text-center text-xl tracking-[0.25em] text-zinc-900 placeholder:text-zinc-400 placeholder:tracking-normal outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                            maxlength="6"
                        />
                    </div>
                    @error('code') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Submit --}}
                <button
                    type="submit"
                    x-bind:disabled="submitting"
                    class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:opacity-75 disabled:cursor-not-allowed"
                >
                    <svg x-show="submitting" class="h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="display: none;">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="submitting ? 'Verifying...' : 'Verify Code'">Verify Code</span>
                </button>
            </form>

            @if (!$hasTotp)
            {{-- Resend Code --}}
            <div x-data="{
                    countdown: {{ $remainingCooldown ?? 0 }},
                    init() {
                        if (this.countdown > 0) {
                            this.startTimer();
                        }
                    },
                    startTimer() {
                        let interval = setInterval(() => {
                            this.countdown--;
                            if (this.countdown <= 0) {
                                clearInterval(interval);
                            }
                        }, 1000);
                    }
                }" 
                class="mt-4 flex flex-col items-center justify-center text-sm"
            >
                <form method="POST" action="{{ route('admin.2fa.resend') }}">
                    @csrf
                    <button 
                        type="submit" 
                        x-bind:disabled="countdown > 0"
                        class="font-medium text-blue-600 transition-colors hover:text-blue-800 disabled:text-zinc-400 disabled:cursor-not-allowed"
                    >
                        Resend Code <span x-show="countdown > 0" x-text="`in ${countdown}s`" style="display: none;"></span>
                    </button>
                </form>
            </div>
            @endif

            {{-- Security / audit note --}}
            <div class="mt-6 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
                <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z"/>
                </svg>
                <span>Protected administrative area. All sign-ins are logged.</span>
            </div>

            {{-- Back to login --}}
            <div class="mt-3 flex justify-center text-sm">
                <a href="{{ route('admin.login') }}" class="font-medium text-zinc-600 transition-colors hover:text-blue-700">Cancel and return to login</a>
            </div>

        </div>
    </div>
</x-layouts.auth.centered>
