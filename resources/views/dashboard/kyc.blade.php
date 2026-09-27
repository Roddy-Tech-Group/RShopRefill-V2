{{--
    Identity Verification (KYC) page — /dashboard/kyc.

    Reads real customer state from users.kyc_status + users.email_verified_at.
    Verification is automated through Smile ID: "Verify now" mints a session at
    POST /api/kyc/start and launches the shared window.rshopKyc modal (the same
    hosted flow the checkout/funding step-up uses); the provider verdict lands
    on the Smile webhook and flips users.kyc_status.

    Verification model:
      - Not verified         → "Basic" badge          → standard limits
      - Email verified       → "Email Verified" badge → standard limits, unlocks Platinum tier
      - ID (KYC) verified    → "Verified" badge       → raised limits, unlocks Diamond tier

    The limits table below renders from SpendingLimitService::sets() — the SAME
    settings-backed values the checkout gate enforces (shadow until
    features.spending_limits_enforce_enabled is on) — so what the customer is
    shown and what the gate enforces can never disagree.
--}}
@php
    $user = auth()->user();

    $emailVerified = (bool) $user?->email_verified_at;

    $kycStatus = $user?->kyc_status ?? 'unsubmitted';
    $kycVerified = $kycStatus === 'verified';

    // Verification level the user has reached.
    $level = $kycVerified ? 'Verified' : ($emailVerified ? 'Email Verified' : 'Basic');

    // Daily / weekly / monthly transaction caps (USD), from the single
    // settings-backed source the checkout gate enforces.
    $limitSets = app(\App\Domain\Kyc\Services\SpendingLimitService::class)->sets();
    $limits = [
        'standard' => $limitSets['standard'],
        'kyc' => $limitSets['verified'],
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
            <div class="rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        {{-- The user's real profile photo (or their initials avatar), matching the settings page - not a generic icon. --}}
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-[#f4f6f5]">
                            <img src="{{ $user?->avatar_url ?: ($user?->initialsAvatar() ?? '') }}" alt="{{ $user?->name ?? 'Account' }}" class="h-full w-full object-cover" loading="lazy">
                        </span>
                        <div>
                            <p class="text-sm text-zinc-600">Your verification level</p>
                            <p class="text-lg font-bold text-black">{{ $level }}</p>
                        </div>
                    </div>

                    @if ($kycVerified)
                        {{-- Same scalloped verification seal the settings page uses. --}}
                        <x-ui.verified-badge class="h-8 w-8" title="Identity verified" />
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
            <div class="flex items-center justify-between gap-4 rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
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
                <div class="flex items-center gap-4 rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
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
                <div class="mt-3 flex items-center gap-4 rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-amber-50">
                        <svg class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-black">Your verification is in review</p>
                        <p class="mt-0.5 text-sm text-zinc-600">Automated checks usually complete within a few minutes; occasionally a manual review can take up to 48 hours. Refresh this page to see your updated status.</p>
                    </div>
                </div>

            @else
                {{-- 'unsubmitted' or 'rejected' — self-serve Smile ID verification. --}}
                @if ($kycStatus === 'rejected')
                    <div class="mt-3 flex items-start gap-3 rounded-[12px] bg-red-50 px-4 py-3">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <p class="text-sm text-red-700">We could not verify your identity last time. Make sure your ID is valid and your face is clearly visible, then try again.</p>
                    </div>
                @endif

                <div
                    x-data="{
                        starting: false,
                        error: '',
                        async start() {
                            this.error = '';
                            this.starting = true;
                            try {
                                const res = await fetch('{{ route('api.kyc.start') }}', {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                                    },
                                });
                                const data = await res.json();
                                if (! res.ok) {
                                    this.error = data.message || 'Could not start verification. Please try again.';
                                    return;
                                }
                                if (data.already_verified) {
                                    window.location.reload();
                                    return;
                                }
                                if (window.rshopKyc) {
                                    window.rshopKyc.launch(data.smile, () => window.location.reload());
                                } else {
                                    this.error = 'The verification tool could not load. Please refresh the page and try again.';
                                }
                            } catch (e) {
                                this.error = 'Network error. Please check your connection and try again.';
                            } finally {
                                this.starting = false;
                            }
                        }
                    }"
                    class="mt-3 rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none sm:p-6"
                >
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5]">
                            <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 7.9-7 9-4-1.1-7-4.5-7-9V6l7-3z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l1.8 1.8L15 10"/>
                            </svg>
                        </span>
                        <div>
                            <p class="text-base font-bold text-black">Verify your identity</p>
                            <p class="mt-0.5 text-sm text-zinc-600">Complete verification to unlock your raised transaction limits.</p>
                        </div>
                    </div>

                    {{-- What you'll need --}}
                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="flex items-center gap-3 rounded-[12px] bg-[#f4f6f5] px-3 py-3">
                            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75A2.25 2.25 0 014.5 4.5h15a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9.75h4.5M6 12.75h3"/>
                            </svg>
                            <span class="text-xs font-semibold text-zinc-700">A government-issued ID <span class="block font-normal text-zinc-500">Passport, national ID, or driver&rsquo;s license</span></span>
                        </div>
                        <div class="flex items-center gap-3 rounded-[12px] bg-[#f4f6f5] px-3 py-3">
                            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
                            </svg>
                            <span class="text-xs font-semibold text-zinc-700">A quick selfie <span class="block font-normal text-zinc-500">Using your device&rsquo;s camera</span></span>
                        </div>
                        <div class="flex items-center gap-3 rounded-[12px] bg-[#f4f6f5] px-3 py-3">
                            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-xs font-semibold text-zinc-700">About 2 minutes <span class="block font-normal text-zinc-500">Most checks complete instantly</span></span>
                        </div>
                    </div>

                    {{-- Country selection happens inside Smile's hosted widget,
                         which lists every country with search. All customers get
                         the worldwide Document Verification flow (see
                         services.smileid.biometric_countries). --}}
                    <p x-show="error" x-cloak class="mt-4 rounded-[12px] bg-red-50 px-3 py-2.5 text-sm text-red-700" x-text="error"></p>

                    {{-- The button always reads the same. Whatever stands in the
                         way (verification switched off, or an email not yet
                         confirmed, which verified-email on api.kyc.start refuses
                         and re-sends the link for) is said on tap, in the error
                         line above, from the server's own message. --}}
                    <button
                        type="button"
                        @click="start()"
                        :disabled="starting"
                        class="mt-5 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60"
                    >
                        <svg x-show="starting" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span x-text="starting ? 'Starting...' : '{{ $kycStatus === 'rejected' ? 'Try again' : 'Verify now' }}'"></span>
                    </button>

                    <div class="mt-4 flex items-start gap-2 rounded-[12px] bg-[#f4f6f5] px-3 py-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                        <p class="text-xs text-zinc-600">Your information is encrypted and used only to verify your identity.</p>
                    </div>
                </div>

            @endif
        </section>

        {{-- ─── Transaction limits ─── --}}
        <section>
            <h2 class="text-sm font-bold text-black">Transaction limits</h2>
            <p class="mt-1 text-sm text-zinc-600">Verified accounts can transact more. Your current limits are highlighted.</p>

            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Standard limits --}}
                <div @class([
                    'rounded-[12px] p-5 shadow-md shadow-zinc-900/[0.06]',
                    'bg-blue-600 text-white' => ! $kycVerified,
                    'bg-[#ffffff] text-zinc-900' => $kycVerified,
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
                    'rounded-[12px] p-5 shadow-md shadow-zinc-900/[0.06]',
                    'bg-blue-600 text-white' => $kycVerified,
                    'bg-[#ffffff] text-zinc-900' => ! $kycVerified,
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
