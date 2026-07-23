<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-[#eff6ff] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="relative z-10 w-full max-w-md">
        {{-- Card --}}
        <div class="overflow-hidden rounded-[12px] border border-red-200 bg-white p-8 shadow-sm dark:border-red-500/20 dark:bg-[#122343] sm:p-10">

            {{-- Alert Icon Header --}}
            <div class="mb-8 flex justify-center">
                <div class="flex h-20 w-20 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                    <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            <div class="relative mt-8 overflow-hidden rounded-[12px] border border-zinc-200 bg-zinc-50 p-5 dark:border-white/10 dark:bg-[#0c1a36]">
                <div class="absolute top-0 left-0 h-full w-1 bg-red-500 dark:bg-red-500"></div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 text-center">Incident Reference ID</p>
                <div class="mt-2 flex items-center justify-center rounded-[12px] bg-white py-3 px-4 shadow-sm dark:bg-[#122343]">
                    <p class="text-xl font-mono font-bold tracking-[0.1em] text-zinc-900 dark:text-white">{{ $incidentId }}</p>
                </div>
            </div>

            <p class="mt-8 text-sm leading-relaxed text-center text-zinc-600 dark:text-zinc-400">
                Financial operations have been disabled. You can unlock your account at any time by logging in and completing the self-service recovery process.
            </p>
            
            <div class="mt-8 flex justify-center">
                <a href="/" class="flex items-center gap-2 rounded-[12px] bg-zinc-100 px-6 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    Return to Homepage
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
