{{--
    Identity Verification (KYC) page — /dashboard/kyc.

    Reads real customer state from users.kyc_status + users.email_verified_at.
    Form submissions POST to KycController::store which creates a KycSubmission
    row; admins review at /admin/kyc/... and flip the user's kyc_status.

    Verification model:
      - Not verified         → "Basic" badge          → standard limits
      - Email verified       → "Email Verified" badge → standard limits, unlocks Platinum tier
      - ID (KYC) verified    → "Verified" badge       → raised limits, unlocks Diamond tier

    Transaction-limit ENFORCEMENT against $limits below is not wired yet —
    the table is display copy until the limit middleware ships.
--}}
@php
    $user = auth()->user();

    $emailVerified = (bool) $user?->email_verified_at;

    // Backend hook: $user->kyc_status. Column not shipped yet, so this resolves to null
    // and falls back to 'unsubmitted'.
    $kycStatus = $user?->kyc_status ?? 'unsubmitted';
    $kycVerified = $kycStatus === 'verified';

    // Verification level the user has reached.
    $level = $kycVerified ? 'Verified' : ($emailVerified ? 'Email Verified' : 'Basic');

    // Daily / weekly / monthly transaction caps (USD). KYC unlocks the higher set.
    $limits = [
        'standard' => ['day' => 2000,  'week' => 5000,  'month' => 10000],
        'kyc'      => ['day' => 5000,  'week' => 10000, 'month' => 15000],
    ];
    $activeLimits = $kycVerified ? $limits['kyc'] : $limits['standard'];

    $icon = 'filter: brightness(0) saturate(100%);';

    // Options for the modern KYC form dropdowns (consumed by the kycSelect Alpine component).
    $countryOptions = collect(array_keys(config('countries.codes', [])))
        ->map(fn ($c) => ['value' => $c, 'label' => $c])
        ->values();
    $documentTypes = [
        ['value' => 'passport',        'label' => 'Passport'],
        ['value' => 'national_id',     'label' => 'National ID card'],
        ['value' => 'drivers_license', 'label' => "Driver's license"],
    ];
@endphp

<x-layouts.dashboard>
    <div class="flex w-full flex-col gap-8">

        {{-- Status flash now pops as the global flash-toast pill (dashboard layout). --}}

        {{-- ─── Header (desktop only — mobile uses the layout's slim top bar) ─── --}}
        <section class="hidden lg:block">
            <h1 class="text-xl font-bold tracking-tight text-black sm:text-3xl">Identity verification</h1>
            <p class="mt-1 text-sm text-zinc-600">Verify your account to raise your transaction limits and unlock the top membership tiers.</p>
        </section>

        {{-- ─── Current status ─── --}}
        <section>
            <div class="rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-blue-50">
                            <img src="{{ asset('assets/customer.svg') }}" alt="" class="h-6 w-6" style="{{ $icon }}">
                        </span>
                        <div>
                            <p class="text-sm text-zinc-600">Your verification level</p>
                            <p class="text-lg font-bold text-black">{{ $level }}</p>
                        </div>
                    </div>

                    @if ($kycVerified)
                        <span class="inline-flex items-center gap-1.5 rounded-[5px] bg-blue-600 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path fill-rule="evenodd" d="M12 1.5l2.6 1.9 3.2-.2 1 3.1 2.7 1.7-1 3.1 1 3.1-2.7 1.7-1 3.1-3.2-.2L12 22.5l-2.6-1.9-3.2.2-1-3.1L2.5 16l1-3.1-1-3.1 2.7-1.7 1-3.1 3.2.2L12 1.5zm-1 13.6l5-5-1.4-1.4-3.6 3.6-1.6-1.6L7 12.1l3 3z" clip-rule="evenodd"/>
                            </svg>
                            Verified
                        </span>
                    @elseif ($emailVerified)
                        <span class="inline-flex items-center rounded-[5px] bg-emerald-500 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white">Email Verified</span>
                    @else
                        <span class="inline-flex items-center rounded-[5px] bg-zinc-500 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white dark:bg-zinc-700 dark:text-zinc-200">Basic</span>
                    @endif
                </div>

                {{-- What this level unlocks --}}
                <div class="mt-4 grid grid-cols-1 gap-2 border-t border-zinc-100 pt-4 text-sm sm:grid-cols-2">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $emailVerified ? 'text-emerald-600' : 'text-zinc-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                        <span class="{{ $emailVerified ? 'text-zinc-700' : 'text-zinc-500' }}">Email verification unlocks the <span class="font-semibold">Platinum</span> membership tier.</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $kycVerified ? 'text-emerald-600' : 'text-zinc-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                        <span class="{{ $kycVerified ? 'text-zinc-700' : 'text-zinc-500' }}">ID verification unlocks the <span class="font-semibold">Diamond</span> tier and raises your limits.</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Email verification --}}
        <section>
            <div class="flex items-center justify-between gap-4 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                <div class="flex items-center gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] {{ $emailVerified ? 'bg-emerald-50' : 'bg-amber-50' }}">
                        <svg class="h-5 w-5 {{ $emailVerified ? 'text-emerald-600' : 'text-amber-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-black">Confirm your email address</p>
                        <p class="mt-0.5 truncate text-sm text-zinc-600">{{ $user?->email }}</p>
                    </div>
                </div>
                @if ($emailVerified)
                    <span class="shrink-0 rounded-[5px] bg-emerald-500 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Done</span>
                @else
                    {{-- Backend hook: point this at the email verification notice / resend route. --}}
                    <a href="{{ route('verification.notice') }}" wire:navigate class="shrink-0 rounded-[12px] bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        Verify email
                    </a>
                @endif
            </div>
        </section>

        {{-- ID verification (KYC) --}}
        <section>
            @if ($kycStatus === 'verified')
                <div class="flex items-center gap-4 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50">
                        <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-black">Your identity is verified</p>
                        <p class="mt-0.5 text-sm text-zinc-600">You have the Verified badge and raised transaction limits.</p>
                    </div>
                </div>

            @elseif ($kycStatus === 'pending')
                <div class="mt-3 flex items-center gap-4 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-amber-50">
                        <svg class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-black">Your documents are under review</p>
                        <p class="mt-0.5 text-sm text-zinc-600">Verification usually takes up to 48 hours. We will notify you once it is complete.</p>
                    </div>
                </div>

            @else
                {{-- 'unsubmitted' or 'rejected' — show the submission form. --}}
                @if ($kycStatus === 'rejected')
                    <div class="mt-3 flex items-start gap-3 rounded-[12px] bg-red-50 px-4 py-3">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <p class="text-sm text-red-700">Your last submission could not be verified. Please check your documents and submit again.</p>
                    </div>
                @endif

                <form
                    method="POST"
                    action="{{ route('kyc.submit') }}"
                    enctype="multipart/form-data"
                    class="mt-3 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none sm:p-6"
                >
                    @csrf

                    @if ($errors->any())
                        <div class="mb-4 rounded-[12px] bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
                            <p class="font-semibold">Please fix the following:</p>
                            <ul class="mt-1 list-disc pl-5 text-xs">
                                @foreach ($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Full legal name --}}
                        <div>
                            <label for="kyc_full_name" class="text-xs font-semibold text-zinc-900">Full legal name</label>
                            <input
                                id="kyc_full_name"
                                name="kyc_full_name"
                                type="text"
                                value="{{ $user?->name }}"
                                placeholder="As shown on your ID"
                                class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                            >
                        </div>

                        {{-- Date of birth — calendar date picker --}}
                        <div>
                            <label class="text-xs font-semibold text-zinc-900">Date of birth</label>
                            <div
                                x-data="kycDatePicker()"
                                @click.outside="open = false"
                                @keydown.escape="open = false"
                                class="relative mt-1.5"
                            >
                                <input type="hidden" name="kyc_dob" :value="value">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    :class="open && 'border-blue-500 ring-2 ring-blue-500/15'"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-left text-sm transition-colors"
                                >
                                    <span :class="value ? 'text-zinc-900' : 'text-zinc-400'" x-text="displayValue || 'Select your date of birth'"></span>
                                    <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                </button>

                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="absolute left-0 z-30 mt-1 w-[300px] rounded-[12px] bg-[#eff6ff] p-3 shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200"
                                >
                                    {{-- Month / year stepper --}}
                                    <div class="flex items-center justify-between">
                                        <div class="flex gap-0.5">
                                            <button type="button" @click="shiftYear(-1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-zinc-100" aria-label="Previous year">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5M11.25 19.5l-7.5-7.5 7.5-7.5"/></svg>
                                            </button>
                                            <button type="button" @click="shiftMonth(-1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-zinc-100" aria-label="Previous month">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                                            </button>
                                        </div>
                                        <span class="text-sm font-bold text-zinc-900" x-text="monthLabel"></span>
                                        <div class="flex gap-0.5">
                                            <button type="button" @click="shiftMonth(1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-zinc-100" aria-label="Next month">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                                            </button>
                                            <button type="button" @click="shiftYear(1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-zinc-100" aria-label="Next year">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 4.5l7.5 7.5-7.5 7.5M12.75 4.5l7.5 7.5-7.5 7.5"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Weekday labels --}}
                                    <div class="mt-3 grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase tracking-wide text-zinc-400">
                                        <template x-for="w in weekdays" :key="w"><span x-text="w"></span></template>
                                    </div>

                                    {{-- Day grid --}}
                                    <div class="mt-1 grid grid-cols-7 gap-1">
                                        <template x-for="(cell, idx) in cells" :key="idx">
                                            <button
                                                type="button"
                                                @click="pick(cell)"
                                                :disabled="!cell || isFuture(cell)"
                                                :class="{
                                                    'invisible': !cell,
                                                    'bg-blue-600 font-bold text-white': cell && isSelected(cell),
                                                    'cursor-not-allowed text-zinc-300': cell && isFuture(cell),
                                                    'text-zinc-700 hover:bg-zinc-100': cell && !isSelected(cell) && !isFuture(cell),
                                                }"
                                                class="flex h-8 w-8 items-center justify-center rounded-[12px] text-sm transition-colors"
                                                x-text="cell"
                                            ></button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Country of residence — searchable dropdown --}}
                        <div>
                            <label class="text-xs font-semibold text-zinc-900">Country of residence</label>
                            <div
                                x-data="kycSelect(@js($countryOptions), '', true)"
                                @click.outside="open = false"
                                @keydown.escape="open = false"
                                class="relative mt-1.5"
                            >
                                <input type="hidden" name="kyc_country" :value="value">
                                <button
                                    type="button"
                                    @click="toggle()"
                                    :class="open && 'border-blue-500 ring-2 ring-blue-500/15'"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-left text-sm transition-colors"
                                >
                                    <span :class="value ? 'text-zinc-900' : 'text-zinc-400'" x-text="selectedLabel || 'Select a country'"></span>
                                    <svg :class="open && 'rotate-180'" class="h-4 w-4 shrink-0 text-zinc-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                </button>

                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="absolute left-0 z-30 mt-1 w-full overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200"
                                >
                                    <div class="border-b border-zinc-100 p-2">
                                        <input
                                            x-ref="search"
                                            x-model="query"
                                            type="text"
                                            placeholder="Search countries"
                                            class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                                        >
                                    </div>
                                    <ul class="max-h-52 overflow-y-auto p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                        <template x-for="opt in filtered" :key="opt.value">
                                            <li>
                                                <button
                                                    type="button"
                                                    @click="pick(opt.value)"
                                                    :class="opt.value === value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-sm transition-colors"
                                                >
                                                    <span x-text="opt.label"></span>
                                                    <svg x-show="opt.value === value" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                    </svg>
                                                </button>
                                            </li>
                                        </template>
                                        <li x-show="filtered.length === 0" class="px-3 py-3 text-center text-sm text-zinc-500">No matches</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        {{-- Document type — dropdown --}}
                        <div>
                            <label class="text-xs font-semibold text-zinc-900">Document type</label>
                            <div
                                x-data="kycSelect(@js($documentTypes), 'passport', false)"
                                @click.outside="open = false"
                                @keydown.escape="open = false"
                                class="relative mt-1.5"
                            >
                                <input type="hidden" name="kyc_document_type" :value="value">
                                <button
                                    type="button"
                                    @click="toggle()"
                                    :class="open && 'border-blue-500 ring-2 ring-blue-500/15'"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-left text-sm transition-colors"
                                >
                                    <span class="text-zinc-900" x-text="selectedLabel"></span>
                                    <svg :class="open && 'rotate-180'" class="h-4 w-4 shrink-0 text-zinc-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                </button>

                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="absolute left-0 z-30 mt-1 w-full overflow-hidden rounded-[12px] bg-[#eff6ff] p-1 shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200"
                                >
                                    <template x-for="opt in options" :key="opt.value">
                                        <button
                                            type="button"
                                            @click="pick(opt.value)"
                                            :class="opt.value === value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                            class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-sm transition-colors"
                                        >
                                            <span x-text="opt.label"></span>
                                            <svg x-show="opt.value === value" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Document number --}}
                        <div class="sm:col-span-2">
                            <label for="kyc_document_number" class="text-xs font-semibold text-zinc-900">Document number</label>
                            <input
                                id="kyc_document_number"
                                name="kyc_document_number"
                                type="text"
                                placeholder="The number printed on your document"
                                class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                            >
                        </div>
                    </div>

                    {{-- Document uploads --}}
                    <p class="mt-5 text-xs font-semibold text-zinc-900">Upload your documents</p>
                    <p class="mt-0.5 text-xs text-zinc-600">Clear photos or scans, JPG or PNG, under 8 MB each.</p>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach ([
                            ['kyc_document_front', 'Document front'],
                            ['kyc_document_back',  'Document back'],
                            ['kyc_selfie',         'Selfie with document'],
                        ] as [$field, $label])
                            <div x-data="{ fileName: '' }">
                                <label
                                    for="{{ $field }}"
                                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[12px] border-2 border-dashed border-zinc-200 bg-zinc-50 px-3 py-5 text-center transition-colors hover:border-blue-400 hover:bg-blue-50"
                                >
                                    <svg class="h-6 w-6 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                    </svg>
                                    <span class="text-xs font-semibold text-zinc-900">{{ $label }}</span>
                                    <span class="text-[11px] text-zinc-500" x-text="fileName || 'Tap to upload'"></span>
                                </label>
                                <input
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    class="hidden"
                                    @change="fileName = $event.target.files[0]?.name || ''"
                                >
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 flex items-start gap-2 rounded-[12px] bg-zinc-50 px-3 py-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                        <p class="text-xs text-zinc-600">Your documents are used only to verify your identity and are stored securely.</p>
                    </div>

                    <button type="submit" class="mt-4 w-full rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        {{ $kycStatus === 'rejected' ? 'Resubmit for verification' : 'Submit for verification' }}
                    </button>
                </form>
            @endif
        </section>

        {{-- ─── Transaction limits ─── --}}
        <section>
            <h2 class="text-sm font-bold text-black">Transaction limits</h2>
            <p class="mt-1 text-sm text-zinc-600">Verified accounts can transact more. Your current limits are highlighted.</p>

            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Standard limits --}}
                <div @class([
                    'rounded-[12px] p-5 ring-1 transition-colors',
                    'bg-blue-600 text-white ring-blue-600' => ! $kycVerified,
                    'bg-white text-zinc-900 ring-zinc-100' => $kycVerified,
                ])>
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold">Standard</p>
                        @unless ($kycVerified)
                            <span class="rounded-[5px] bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide">Current</span>
                        @endunless
                    </div>
                    <p @class(['mt-0.5 text-xs', 'text-white/80' => ! $kycVerified, 'text-zinc-600' => $kycVerified])>Basic and email-verified accounts</p>
                    <dl class="mt-4 space-y-2 text-sm">
                        @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'] as $key => $label)
                            <div class="flex items-center justify-between">
                                <dt @class(['text-white/80' => ! $kycVerified, 'text-zinc-600' => $kycVerified])>{{ $label }}</dt>
                                <dd class="font-bold">${{ number_format($limits['standard'][$key]) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- KYC limits --}}
                <div @class([
                    'rounded-[12px] p-5 ring-1 transition-colors',
                    'bg-blue-600 text-white ring-blue-600' => $kycVerified,
                    'bg-white text-zinc-900 ring-zinc-100' => ! $kycVerified,
                ])>
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold">Verified (KYC)</p>
                        @if ($kycVerified)
                            <span class="rounded-[5px] bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide">Current</span>
                        @else
                            <span class="rounded-[5px] bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">Unlock</span>
                        @endif
                    </div>
                    <p @class(['mt-0.5 text-xs', 'text-white/80' => $kycVerified, 'text-zinc-600' => ! $kycVerified])>ID-verified accounts</p>
                    <dl class="mt-4 space-y-2 text-sm">
                        @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'] as $key => $label)
                            <div class="flex items-center justify-between">
                                <dt @class(['text-white/80' => $kycVerified, 'text-zinc-600' => ! $kycVerified])>{{ $label }}</dt>
                                <dd class="font-bold">${{ number_format($limits['kyc'][$key]) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </section>

    </div>
</x-layouts.dashboard>
