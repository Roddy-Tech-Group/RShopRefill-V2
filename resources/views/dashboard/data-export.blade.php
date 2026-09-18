<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-[#f4f6f5] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="relative z-10 w-full max-w-md">
        <div class="overflow-hidden rounded-[12px] bg-white p-8 shadow-sm dark:bg-[#122343] sm:p-10">

            <div class="mb-8 flex justify-center">
                <div class="flex h-20 w-20 items-center justify-center rounded-full bg-[#f4f6f5] text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                    <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                </div>
            </div>

            <div class="text-center">
                <h1 class="text-2xl font-extrabold tracking-tight text-zinc-900 dark:text-white">Download your data</h1>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    We sent a 6-digit code to <span class="font-semibold text-zinc-900 dark:text-white">{{ $maskedEmail }}</span>. Enter it to confirm it is you, and your PDF will download.
                </p>
            </div>

            @if (session('status'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100 transform translate-y-0"
                     x-transition:leave-end="opacity-0 transform -translate-y-2"
                     class="mt-4 rounded-lg bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-400">
                    {{ session('status') }}
                </div>
            @endif

            @foreach (['resend' => 'amber', 'data_export' => 'red'] as $field => $tone)
                @error($field)
                    <div class="mt-4 rounded-lg bg-{{ $tone }}-50 p-4 text-sm text-{{ $tone }}-800 dark:bg-{{ $tone }}-900/30 dark:text-{{ $tone }}-400">
                        {{ $message }}
                    </div>
                @enderror
            @endforeach

            <form method="POST" action="{{ route('privacy.export.verify') }}" class="mt-8 space-y-6" x-data="{ loading: false }" @submit="loading = true; setTimeout(() => loading = false, 4000)">
                @csrf

                <div>
                    <label for="otp" class="sr-only">Verification code</label>
                    <div class="relative">
                        <input id="otp" name="otp" type="text" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="------" autocomplete="one-time-code" autofocus
                            class="block w-full rounded-[12px] border border-zinc-200 bg-[#f4f6f5] px-4 py-4 text-center text-3xl font-mono tracking-[0.5em] text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/20 dark:border-[#24364f] dark:bg-[#0c1a36] dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20">
                        @error('otp')
                            <div class="absolute -bottom-6 left-0 right-0 text-center">
                                <p class="text-xs font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                            </div>
                        @enderror
                    </div>
                </div>

                <button type="submit" :disabled="loading"
                    class="flex w-full cursor-pointer items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-base font-bold text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-[#0c1a36]"
                    :class="loading ? 'opacity-80' : ''">
                    <span x-show="!loading">Verify and download</span>
                    <span class="flex items-center gap-2" x-show="loading" x-cloak>
                        <svg class="h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Preparing your file...
                    </span>
                </button>
            </form>

            <div class="mt-8 flex flex-col items-center gap-4 text-center">
                <form method="POST" action="{{ route('privacy.export.send') }}" x-data="{
                    resending: false,
                    cooldown: {{ $cooldown ?? 0 }},
                    init() { if (this.cooldown > 0) { setInterval(() => { if (this.cooldown > 0) this.cooldown--; }, 1000); } },
                    get cooldownText() {
                        if (this.cooldown <= 0) return '';
                        if (this.cooldown < 60) return `in ${this.cooldown}s`;
                        let m = Math.floor(this.cooldown / 60); let s = this.cooldown % 60;
                        return `in ${m}m ${s > 0 ? s + 's' : ''}`.trim();
                    }
                }" @submit="if (cooldown > 0) { $event.preventDefault(); return; } resending = true">
                    @csrf
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Didn't receive the code?
                        <button type="submit" :disabled="resending || cooldown > 0" :class="(resending || cooldown > 0) ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:text-blue-500 dark:hover:text-blue-300'" class="font-semibold text-blue-600 transition-colors dark:text-blue-400">
                            <span x-show="!resending && cooldown <= 0">Resend now</span>
                            <span x-show="!resending && cooldown > 0" x-text="'Resend ' + cooldownText" x-cloak></span>
                            <span x-show="resending" x-cloak>Sending...</span>
                        </button>
                    </p>
                </form>

                <a href="{{ route('dashboard.profile') }}" class="text-xs font-medium text-zinc-500 underline transition-colors hover:text-zinc-800 dark:text-zinc-500 dark:hover:text-zinc-300">
                    Back to settings
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
