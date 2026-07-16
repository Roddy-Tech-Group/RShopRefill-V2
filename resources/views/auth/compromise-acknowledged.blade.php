<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-[#eff6ff] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    {{-- Ambient Red/Danger Background Glow --}}
    <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-[10%] left-[20%] h-[500px] w-[500px] rounded-full bg-red-400/20 blur-[100px] dark:bg-red-600/20"></div>
        <div class="absolute bottom-[10%] right-[20%] h-[400px] w-[400px] rounded-full bg-rose-400/20 blur-[100px] dark:bg-rose-600/20"></div>
    </div>

    <div class="relative z-10 w-full max-w-md">
        {{-- Danger Glassmorphic Card --}}
        <div class="overflow-hidden rounded-[24px] border border-red-200/50 bg-white/80 p-8 shadow-2xl backdrop-blur-xl dark:border-red-500/20 dark:bg-[#1f0f15]/80 sm:p-10">
            
            {{-- Alert Icon Header --}}
            <div class="mb-8 flex justify-center">
                <div class="relative flex h-20 w-20 items-center justify-center rounded-full bg-red-50 text-red-600 shadow-inner dark:bg-red-900/30 dark:text-red-400">
                    <div class="absolute inset-0 rounded-full border border-red-200/50 animate-[ping_3s_cubic-bezier(0,0,0.2,1)_infinite] dark:border-red-500/20"></div>
                    <div class="absolute inset-0 rounded-full border border-red-300/50 dark:border-red-500/30"></div>
                    <svg class="h-10 w-10 relative z-10 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
            </div>
            
            <div class="text-center">
                <h1 class="text-2xl font-extrabold tracking-tight text-red-700 dark:text-red-400">Account Secured</h1>
                <p class="mt-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                    You successfully flagged an unauthorized login. Your account has been placed in <span class="font-semibold text-red-600 dark:text-red-400">Protected Mode</span> to secure your funds.
                </p>
            </div>
            
            {{-- Incident ID Block --}}
            <div class="relative mt-8 overflow-hidden rounded-[16px] border border-zinc-200/50 bg-zinc-50/80 p-5 shadow-inner dark:border-zinc-700/50 dark:bg-black/40">
                <div class="absolute top-0 left-0 h-full w-1 bg-red-500 dark:bg-red-500"></div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 text-center">Incident Reference ID</p>
                <div class="mt-2 flex items-center justify-center rounded-md bg-white py-3 px-4 shadow-sm dark:bg-[#0c1a36]">
                    <p class="text-xl font-mono font-bold tracking-[0.1em] text-zinc-900 dark:text-white">{{ $incidentId }}</p>
                </div>
            </div>

            <p class="mt-8 text-sm leading-relaxed text-center text-zinc-600 dark:text-zinc-400">
                Financial operations have been disabled. You can unlock your account at any time by logging in and completing the self-service recovery process.
            </p>
            
            <div class="mt-8 flex justify-center">
                <a href="/" class="group flex items-center gap-2 rounded-full bg-zinc-100 px-6 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    <svg class="h-4 w-4 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    Return to Homepage
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
