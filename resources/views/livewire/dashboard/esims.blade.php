<?php

use App\Support\CustomerEsims;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.dashboard')]
#[Title('My eSIMs')]
class extends Component {
    /** Matches plan name, country, ICCID or order number. */
    public string $search = '';

    /** all | delivered | preparing | failed */
    public string $status = 'all';

    public function with(): array
    {
        $esims = CustomerEsims::for(Auth::user());

        $counts = [
            'all' => $esims->count(),
            'delivered' => $esims->where('status', 'delivered')->count(),
            'preparing' => $esims->where('status', 'preparing')->count(),
            'failed' => $esims->where('status', 'failed')->count(),
        ];

        $shown = $esims
            ->when($this->status !== 'all', fn ($c) => $c->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($c) {
                $term = mb_strtolower(trim($this->search));

                return $c->filter(fn (array $esim) => collect([$esim['name'], $esim['country'], $esim['iccid'], $esim['order_number']])
                    ->filter()
                    ->contains(fn ($value) => str_contains(mb_strtolower((string) $value), $term)));
            })
            ->values();

        return ['esims' => $shown, 'counts' => $counts];
    }
}; ?>

@php
    // [label, pill classes]
    $statusPill = [
        'delivered' => ['Delivered', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
        'preparing' => ['Preparing', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
        'failed'    => ['Not delivered', 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'],
    ];
    $tabs = ['all' => 'All', 'delivered' => 'Delivered', 'preparing' => 'Preparing', 'failed' => 'Not delivered'];
    $primaryBtn = 'inline-flex items-center justify-center gap-2 rounded-[12px] bg-[#0c1337] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#1a2560] dark:bg-blue-600 dark:hover:bg-blue-500';
    $secondaryBtn = 'inline-flex items-center justify-center gap-2 rounded-[12px] bg-[#f4f6f5] px-4 py-2.5 text-sm font-semibold text-zinc-900 transition-shadow hover:shadow-lg hover:shadow-zinc-900/[0.10] dark:text-white';
@endphp

<div class="flex w-full flex-col gap-5">

    {{-- Heading --}}
    <div>
        <h1 class="hidden text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl lg:block dark:text-white">My eSIMs</h1>
        <p class="mt-1 text-sm text-[#0c1337] dark:text-zinc-300">Every eSIM you have bought, with its QR code, install links and top-ups in one place.</p>
    </div>

    @if ($counts['all'] > 0)
        {{-- Search + status tabs --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-sm">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-[#0c1337] dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by country, plan, ICCID or order"
                    aria-label="Search your eSIMs"
                    class="w-full rounded-[12px] border border-zinc-300 bg-[#ffffff] py-2.5 pl-12 pr-4 text-sm text-zinc-900 outline-none transition-colors placeholder:text-zinc-500 hover:border-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/10 dark:text-white"
                >
            </div>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filter eSIMs by status">
                @foreach ($tabs as $key => $label)
                    @continue($key !== 'all' && $counts[$key] === 0)
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('status', '{{ $key }}')"
                        aria-selected="{{ $status === $key ? 'true' : 'false' }}"
                        class="{{ $status === $key ? 'bg-[#0c1337] text-white dark:bg-blue-600' : 'bg-[#ffffff] text-zinc-700 hover:text-zinc-900 dark:text-zinc-300' }} inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors"
                    >
                        {{ $label }}
                        <span class="tabular-nums opacity-70">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($counts['all'] === 0)
        {{-- Nothing bought yet --}}
        <div class="flex flex-col items-center rounded-[15px] bg-[#ffffff] px-6 py-12 text-center shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
            <span class="flex h-14 w-14 items-center justify-center rounded-[15px] bg-[#f4f6f5] text-[#0c1337] dark:text-zinc-200">
                <x-ui.anim-icon file="esim.svg" tone="inherit" class="h-7 w-7" />
            </span>
            <h2 class="mt-4 text-base font-bold text-zinc-900 dark:text-white">No eSIMs yet</h2>
            <p class="mt-1 max-w-sm text-sm text-zinc-600 dark:text-zinc-300">When you buy an eSIM it shows up here, ready to install and top up.</p>
            <a href="{{ route('dashboard.shop.esims') }}" wire:navigate.hover class="{{ $secondaryBtn }} mt-5">Browse eSIMs</a>
        </div>
    @elseif ($esims->isEmpty())
        <div class="rounded-[15px] bg-[#ffffff] px-6 py-10 text-center text-sm text-zinc-600 shadow-md shadow-zinc-900/[0.06] dark:text-zinc-300 dark:shadow-none">
            No eSIMs match your search.
        </div>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($esims as $esim)
                @php
                    [$pillLabel, $pillTone] = $statusPill[$esim['status']];
                    $plan = collect([
                        $esim['data'],
                        $esim['days'] > 0 ? $esim['days'].' '.\Illuminate\Support\Str::plural('day', $esim['days']) : null,
                    ])->filter()->implode(' · ');
                    $hasCredentials = $esim['qr'] || $esim['lpa'] || $esim['code'] || $esim['iccid'];
                @endphp

                <article wire:key="esim-{{ $esim['id'] }}" x-data="{ open: false }" class="rounded-[15px] bg-[#ffffff] p-5 shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                    {{-- Summary --}}
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-[#0c1337] dark:text-zinc-200">
                            <x-ui.anim-icon file="esim.svg" tone="inherit" class="h-6 w-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-bold text-zinc-900 dark:text-white">{{ $esim['name'] }}</h2>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $pillTone }}">{{ $pillLabel }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-sm text-zinc-600 dark:text-zinc-300">
                                {{ collect([$esim['country'], $plan])->filter()->implode(' · ') }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Bought {{ $esim['purchased_at']?->format('j M Y') }}
                                @if ($esim['order_number'])
                                    <span class="px-0.5">·</span>
                                    <a href="{{ route('shop.order', $esim['order_number']) }}" class="font-medium text-[#0c1337] underline-offset-2 hover:underline dark:text-blue-300">{{ $esim['order_number'] }}</a>
                                @endif
                                @if ($esim['topups'] > 0)
                                    <span class="px-0.5">·</span>
                                    {{ $esim['topups'] }} {{ \Illuminate\Support\Str::plural('top-up', $esim['topups']) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Actions --}}
                    @if ($esim['status'] === 'delivered')
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($esim['install'])
                                {{-- iPhone (iOS 17.4+) opens the eSIM install flow directly. --}}
                                <a href="{{ $esim['install'] }}" class="{{ $primaryBtn }}">Install on iPhone</a>
                            @endif
                            @if ($hasCredentials)
                                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="{{ $secondaryBtn }}">
                                    <span x-text="open ? 'Hide install details' : 'QR code and install details'">QR code and install details</span>
                                </button>
                            @endif
                            @if ($esim['can_top_up'])
                                <a href="{{ route('dashboard.esim.topup', $esim['id']) }}" wire:navigate class="{{ $secondaryBtn }}">Top up eSIM</a>
                            @endif
                            @if ($esim['manage'])
                                <a href="{{ $esim['manage'] }}" target="_blank" rel="noopener" class="{{ $secondaryBtn }}">
                                    Check usage
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                </a>
                            @endif
                        </div>
                    @elseif ($esim['status'] === 'preparing')
                        <p class="mt-4 rounded-[12px] bg-[#f4f6f5] px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">We are preparing this eSIM. Its QR code and install details appear here as soon as it is ready.</p>
                    @else
                        <p class="mt-4 rounded-[12px] bg-[#f4f6f5] px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            This eSIM could not be delivered.
                            <a href="{{ route('shop.contact') }}?subject={{ rawurlencode('Help with eSIM order '.$esim['order_number']) }}" wire:navigate class="font-semibold text-[#0c1337] underline underline-offset-2 dark:text-blue-300">Contact support</a>
                        </p>
                    @endif

                    {{-- Install details: QR for scanning from another device, plus the
                         manual entry values, each copyable. --}}
                    @if ($esim['status'] === 'delivered' && $hasCredentials)
                        <div x-show="open" x-collapse style="display: none;">
                            <div class="mt-4 grid gap-4 rounded-[12px] bg-[#f4f6f5] p-4 sm:grid-cols-[auto_1fr] dark:bg-white/5">
                                @if ($esim['qr'])
                                    <div class="flex flex-col items-center gap-2">
                                        <span class="theme-static rounded-[12px] bg-white p-3">
                                            <img src="{{ $esim['qr'] }}" alt="eSIM QR code" class="h-40 w-40 object-contain" loading="lazy">
                                        </span>
                                        <p class="max-w-[11rem] text-center text-xs text-zinc-600 dark:text-zinc-400">Scan from another device to install.</p>
                                    </div>
                                @endif
                                <div class="flex min-w-0 flex-col gap-2">
                                    @foreach (array_filter(['SM-DP+ address' => $esim['lpa'], 'Activation code' => $esim['code'], 'ICCID' => $esim['iccid'], 'Access code' => $esim['access']]) as $credLabel => $credValue)
                                        <div class="flex items-center gap-3 rounded-[12px] bg-[#ffffff] px-4 py-2.5" wire:key="esim-{{ $esim['id'] }}-{{ $credLabel }}">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $credLabel }}</p>
                                                <p class="break-all font-mono text-sm text-zinc-900 dark:text-white">{{ $credValue }}</p>
                                            </div>
                                            <button
                                                type="button"
                                                x-data="{ copied: false }"
                                                @click="navigator.clipboard.writeText(@js($credValue)); copied = true; setTimeout(() => copied = false, 1500)"
                                                class="shrink-0 text-[#0c1337] transition-colors hover:text-blue-700 dark:text-zinc-300"
                                                aria-label="Copy {{ $credLabel }}"
                                            >
                                                <svg x-show="! copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/></svg>
                                                <svg x-show="copied" x-cloak class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            </button>
                                        </div>
                                    @endforeach
                                    <a href="{{ route('shop.esim-guide') }}" class="mt-1 text-sm font-medium text-[#0c1337] underline underline-offset-2 dark:text-blue-300">How to install an eSIM</a>
                                </div>
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
