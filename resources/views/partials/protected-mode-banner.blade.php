<div class="mb-6 rounded-[12px] bg-amber-50 p-4 ring-1 ring-amber-500/20 dark:bg-amber-500/10 dark:ring-amber-500/30">
    <div class="flex flex-col sm:flex-row sm:items-start gap-4">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <div class="flex-1">
            <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Your account is temporarily protected.</h3>
            <div class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                <p>We detected unusual activity and have restricted financial actions to protect your funds.</p>
            </div>
            <div class="mt-3">
                <a href="{{ route('security.recover') }}" wire:navigate class="inline-flex items-center rounded-[8px] bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 transition-colors">
                    Recover now
                </a>
            </div>
        </div>
    </div>
</div>
