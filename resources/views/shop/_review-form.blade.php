@php
    /**
     * Shared customer review form.
     *
     * Pass `reviewOrderNumber` when the form is rendered on an order page: the
     * review is then tied to that order so its rating rolls up under every gift
     * card the customer bought. Omit it on the public reviews page.
     *
     * Open to everyone: signed-in customers review under their account name
     * (shown as "Posting as ..."); guests type a name. Either way it saves
     * unpublished for admin approval.
     */
    $reviewOrderNumber = $reviewOrderNumber ?? null;
@endphp

<div
    class="text-center"
    x-data="{
        rating: 0, hover: 0, name: @js(auth()->user()?->name ?? ''), body: '',
        open: false, loading: false, error: '', submitted: false,
        async submit() {
            this.error = '';
            if (this.rating < 1) { this.error = 'Please pick a star rating.'; return; }
            if ((this.name || '').trim().length < 2) { this.error = 'Please enter your name.'; return; }
            if ((this.body || '').trim().length < 8) { this.error = 'Please write a little more about your experience.'; return; }
            this.loading = true;
            try {
                const res = await fetch('{{ route('reviews.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ author_name: this.name, body: this.body, rating: this.rating, order_number: @js($reviewOrderNumber) }),
                });
                if (! res.ok) { throw new Error('failed'); }
                this.submitted = true;
            } catch (e) {
                this.error = 'Could not submit your review. Please try again.';
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <h2 class="text-base font-bold text-zinc-900 dark:text-white">Share your experience</h2>
    <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Tap a star to rate and leave a quick review.</p>

    {{-- Interactive stars - click to set the rating and open the form. --}}
    <div x-show="! submitted" class="mt-5 flex items-center justify-center gap-3" @mouseleave="hover = 0">
        <template x-for="i in 5" :key="i">
            <button type="button" @click="rating = i; open = true" @mouseenter="hover = i" :aria-label="`Rate ${i} out of 5`" class="transition-transform duration-200 hover:scale-110">
                <svg class="h-9 w-9 transition-colors duration-200" :class="(hover || rating) >= i ? 'fill-blue-600 stroke-blue-600 dark:fill-blue-400 dark:stroke-blue-400' : 'fill-transparent stroke-zinc-300 dark:fill-blue-400/10 dark:stroke-blue-400/50'" viewBox="0 0 24 24" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">
                    <path d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/>
                </svg>
            </button>
        </template>
    </div>

    {{-- Inline dropdown form, revealed once a star is tapped. --}}
    <div x-show="open && ! submitted" x-collapse x-cloak class="mx-auto mt-5 max-w-md text-left">
        @auth
            {{-- Signed in: name is taken from the account, not typed. --}}
            <p class="rounded-[12px] bg-blue-50 px-3 py-2 text-xs text-zinc-600 dark:bg-blue-500/10 dark:text-zinc-300">
                Posting as <span class="font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</span>
            </p>
        @else
            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">Your name</label>
            <input x-model="name" type="text" maxlength="80" placeholder="e.g. Sarah J." class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white">
        @endauth

        <label class="mt-3 block text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">Your review</label>
        <textarea x-model="body" rows="4" maxlength="1000" placeholder="What did you like? Was delivery fast?" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"></textarea>

        <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs font-medium text-red-600"></p>

        <button type="button" @click="submit()" :disabled="loading" class="mt-4 w-full rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
            <span x-show="! loading">Submit review</span>
            <span x-show="loading" x-cloak>Submitting...</span>
        </button>
    </div>

    {{-- Thank-you state --}}
    <div x-show="submitted" x-cloak class="mx-auto mt-5 max-w-md rounded-[12px] bg-emerald-50 px-5 py-4 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30">
        <p class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            Thank you! Your review will appear once our team approves it.
        </p>
    </div>
</div>
