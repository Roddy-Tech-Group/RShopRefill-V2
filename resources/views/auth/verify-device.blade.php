<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-[#eff6ff] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    {{-- Ambient Background Glow --}}
    <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-[10%] left-[20%] h-[500px] w-[500px] rounded-full bg-blue-400/20 blur-[100px] dark:bg-blue-600/20"></div>
        <div class="absolute bottom-[10%] right-[20%] h-[400px] w-[400px] rounded-full bg-cyan-400/20 blur-[100px] dark:bg-cyan-600/20"></div>
    </div>

    <div class="relative z-10 w-full max-w-md">
        {{-- Glassmorphic Card --}}
        <div class="overflow-hidden rounded-[24px] border border-white/40 bg-white/70 p-8 shadow-2xl backdrop-blur-xl dark:border-white/10 dark:bg-[#122343]/70 sm:p-10">
            
            {{-- Icon Header --}}
            <div class="mb-8 flex justify-center">
                <div class="relative flex h-20 w-20 items-center justify-center rounded-full bg-blue-50 text-blue-600 shadow-inner dark:bg-blue-900/30 dark:text-blue-400">
                    <div class="absolute inset-0 rounded-full border border-blue-200/50 animate-[spin_4s_linear_infinite] dark:border-blue-500/20"></div>
                    <svg class="h-10 w-10 relative z-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
            </div>

            <div class="text-center">
                <h1 class="text-2xl font-extrabold tracking-tight text-zinc-900 dark:text-white">Verify Your Device</h1>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    For your security, please enter the 6-digit authentication code sent to your email to authorize this new device.
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

            @error('resend')
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                     x-transition:leave="transition ease-in duration-300" 
                     x-transition:leave-start="opacity-100 transform translate-y-0" 
                     x-transition:leave-end="opacity-0 transform -translate-y-2"
                     class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                    {{ $message }}
                </div>
            @enderror

            @error('error')
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                     x-transition:leave="transition ease-in duration-300" 
                     x-transition:leave-start="opacity-100 transform translate-y-0" 
                     x-transition:leave-end="opacity-0 transform -translate-y-2"
                     class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            <form method="POST" action="{{ route('security.verify-device.verify') }}" class="mt-8 space-y-6" x-data="{ loading: false }" @submit="loading = true">
                @csrf

                <div>
                    <label for="otp" class="sr-only">Verification Code</label>
                    <div class="relative">
                        <input id="otp" name="otp" type="text" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="------" autocomplete="one-time-code"
                            class="block w-full rounded-[16px] border border-zinc-300 bg-white px-4 py-4 text-center text-3xl font-mono tracking-[0.5em] text-zinc-900 shadow-inner outline-none transition-all focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/20 dark:border-[#24364f]/50 dark:bg-[#0c1a36]/50 dark:text-white dark:focus:border-blue-400 dark:focus:bg-[#0c1a36] dark:focus:ring-blue-400/20">
                        
                        @error('otp') 
                            <div class="absolute -bottom-6 left-0 right-0 text-center">
                                <p class="text-xs font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                            </div>
                        @enderror
                    </div>
                </div>

                <button type="submit" :disabled="loading"
                    class="group relative flex w-full cursor-pointer items-center justify-center overflow-hidden rounded-[14px] bg-blue-600 px-4 py-3.5 text-base font-bold text-white shadow-[0_8px_20px_-6px_rgba(37,99,235,0.5)] transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-[#0c1a36]"
                    :class="loading ? 'opacity-80' : 'hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-[0_12px_25px_-8px_rgba(37,99,235,0.6)]'">
                    
                    {{-- Default State --}}
                    <span class="relative z-10 flex items-center gap-2" x-show="!loading">
                        Verify Device
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </span>

                    {{-- Loading State --}}
                    <span class="relative z-10 flex items-center gap-2" x-show="loading" x-cloak>
                        <svg class="h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Verifying...
                    </span>

                    <div class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/20 to-transparent transition-transform duration-700" :class="loading ? '' : 'group-hover:translate-x-full'"></div>
                </button>
            </form>
            
            <div class="mt-8 flex flex-col items-center gap-4 text-center">
                <form method="POST" action="{{ route('security.verify-device.send') }}" x-data="{ 
                    resending: false,
                    cooldown: {{ $cooldown ?? 0 }},
                    init() {
                        if (this.cooldown > 0) {
                            setInterval(() => {
                                if (this.cooldown > 0) this.cooldown--;
                            }, 1000);
                        }
                    },
                    get cooldownText() {
                        if (this.cooldown <= 0) return '';
                        if (this.cooldown < 60) return `in ${this.cooldown}s`;
                        let m = Math.floor(this.cooldown / 60);
                        let s = this.cooldown % 60;
                        return `in ${m}m ${s > 0 ? s + 's' : ''}`.trim();
                    }
                }" @submit="if(cooldown > 0) { $event.preventDefault(); return; } resending = true">
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
                
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="cursor-pointer text-xs font-medium text-zinc-500 underline transition-colors hover:text-zinc-800 dark:text-zinc-500 dark:hover:text-zinc-300">
                        Cancel and log out
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
