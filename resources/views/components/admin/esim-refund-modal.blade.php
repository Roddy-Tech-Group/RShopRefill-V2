{{--
    The eSIM refund request dialog. Rendered inside a Livewire page that uses
    the RefundsEsims trait, whose properties and actions the wire: bindings
    name. The reason picker is a listbox (keyboard: arrows, Enter, Escape);
    its menu is fixed to the viewport so the dialog's scroll box never clips it.
    Keep double quotes out of the Alpine expressions: they sit inside
    double-quoted attributes.
--}}
@props(['esim', 'amount' => null, 'reasons' => [], 'error' => null])

<div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="esim-refund-title">
    <div wire:click="closeRefund" class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="relative flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-[12px] bg-white shadow-2xl dark:bg-[#1d3252]">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
            <div>
                <h3 id="esim-refund-title" class="text-sm font-bold text-zinc-900 dark:text-white">Request a refund</h3>
                <p class="mt-0.5 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->iccid }}</p>
            </div>
            <button type="button" wire:click="closeRefund" aria-label="Close" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form wire:submit="confirmRefund" class="flex min-h-0 flex-1 flex-col"
            x-data="{
                open: false,
                top: 0,
                left: 0,
                width: 0,
                reason: $wire.entangle('refundReason'),
                toggle() {
                    const box = this.$refs.trigger.getBoundingClientRect();
                    this.left = box.left;
                    this.width = box.width;
                    this.top = window.innerHeight - box.bottom < 320 ? Math.max(8, box.top - 314) : box.bottom + 6;
                    this.open = ! this.open;
                },
                openAndFocus() {
                    if (! this.open) { this.toggle(); }
                    this.$nextTick(() => (this.$refs.list.querySelector('[aria-selected=true]') || this.$refs.list.querySelector('[role=option]'))?.focus());
                },
                choose(value) {
                    this.reason = value;
                    this.open = false;
                    this.$refs.trigger.focus();
                },
            }"
            @resize.window="open = false"
        >
            <div class="space-y-5 overflow-y-auto px-5 py-4" @scroll="open = false">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</p>
                        <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $esim->user?->name ?? 'Deleted account' }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->user?->email }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Wallet credit on completion</p>
                        <p class="mt-1 text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $amount }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">To their {{ $siteName }} wallet, order {{ $esim->order?->order_number }}</p>
                    </div>
                </div>

                @if ($error)
                    <div class="rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30" role="alert">{{ $error }}</div>
                @endif

                {{-- Reason picker --}}
                <div @keydown.escape.stop="open = false">
                    <p id="refund-reason-label" class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Reason</p>
                    <button
                        type="button"
                        x-ref="trigger"
                        @click="toggle()"
                        @keydown.arrow-down.prevent="openAndFocus()"
                        @keydown.arrow-up.prevent="openAndFocus()"
                        aria-haspopup="listbox"
                        :aria-expanded="open"
                        aria-labelledby="refund-reason-label"
                        @class([
                            'group mt-1.5 flex min-h-11 w-full items-center justify-between gap-3 rounded-[12px] border bg-white px-3.5 py-2 text-left shadow-sm shadow-zinc-900/[0.03] transition focus:outline-none focus:ring-4 dark:bg-[#0c1a36]',
                            'border-red-300 focus:border-red-400 focus:ring-red-500/10 dark:border-red-500/50' => $errors->has('refundReason'),
                            'border-zinc-200 hover:border-zinc-300 focus:border-blue-500 focus:ring-blue-500/10 dark:border-zinc-700/60 dark:hover:border-zinc-600' => ! $errors->has('refundReason'),
                        ])
                        :class="open ? 'border-blue-500 ring-4 ring-blue-500/10' : ''"
                    >
                        <span class="min-w-0">
                            <span x-show="! reason" class="block text-sm text-zinc-400 dark:text-zinc-500">Choose a reason</span>
                            @foreach ($reasons as $value => $label)
                                <span x-show="reason === '{{ $value }}'" x-cloak class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</span>
                            @endforeach
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-150" :class="open ? 'rotate-180 text-blue-500' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-ref="list"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        @click.outside="open = false"
                        :style="`top: ${top}px; left: ${left}px; width: ${width}px`"
                        role="listbox"
                        aria-labelledby="refund-reason-label"
                        class="fixed z-[60] max-h-[308px] overflow-y-auto overscroll-contain rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:shadow-black/30 dark:ring-white/10"
                    >
                        @foreach ($reasons as $value => $label)
                            <button
                                type="button"
                                role="option"
                                :aria-selected="reason === '{{ $value }}'"
                                @click="choose('{{ $value }}')"
                                @keydown.arrow-down.prevent="$el.nextElementSibling?.focus()"
                                @keydown.arrow-up.prevent="$el.previousElementSibling ? $el.previousElementSibling.focus() : $refs.trigger.focus()"
                                @keydown.tab="open = false"
                                class="flex w-full items-center gap-3 rounded-[8px] px-3 py-2.5 text-left transition-colors hover:bg-zinc-50 focus:bg-zinc-50 focus:outline-none dark:hover:bg-[#26416b]/70 dark:focus:bg-[#26416b]/70"
                                :class="reason === '{{ $value }}' ? 'bg-blue-50/80 dark:bg-blue-500/15' : ''"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-zinc-900 dark:text-white" :class="reason === '{{ $value }}' ? 'text-blue-700 dark:text-blue-300' : ''">{{ $label }}</span>
                                </span>
                                <svg x-show="reason === '{{ $value }}'" x-cloak class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </button>
                        @endforeach
                    </div>

                    @error('refundReason') <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Only "Other" needs explaining; every other reason speaks for itself. --}}
                <div x-show="reason === 'OTHERS'" x-cloak x-transition.opacity>
                    <label for="refund-note" class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">What happened</label>
                    <textarea id="refund-note" wire:model="refundNote" rows="3" maxlength="255" placeholder="Explain in a sentence. The provider and the customer will see this." @class([
                        'mt-1.5 w-full rounded-[12px] border bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-sm shadow-zinc-900/[0.03] outline-none transition focus:ring-4 dark:bg-[#0c1a36] dark:text-white',
                        'border-red-300 focus:border-red-400 focus:ring-red-500/10 dark:border-red-500/50' => $errors->has('refundNote'),
                        'border-zinc-200 focus:border-blue-500 focus:ring-blue-500/10 dark:border-zinc-700/60' => ! $errors->has('refundNote'),
                    ])></textarea>
                    @error('refundNote') <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                <button type="button" wire:click="closeRefund" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="confirmRefund" class="inline-flex items-center gap-2 rounded-[12px] bg-red-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-red-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="confirmRefund">Request refund</span>
                    <span wire:loading wire:target="confirmRefund">Checking...</span>
                </button>
            </div>
        </form>
    </div>
</div>
