<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Rcoin Rewards Settings')]
class extends Component {
    /**
     * Live form state. Keyed by setting `key` exactly as it appears in the
     * `settings` table - that way `save()` can iterate without remapping.
     * Booleans get coerced to true/false on load, numerics stay as-is so
     * <input type="number"> binds without surprises.
     */
    public array $values = [];

    public string $savedKey = '';

    /**
     * Grouped schema - mirrors the four sections in SettingsSeeder so the UI
     * tells the same story as the seeder file. Each row carries:
     *   - key      → settings table key (source of truth)
     *   - label    → human-readable form label
     *   - type     → 'boolean' | 'integer' | 'float'
     *   - help     → one-liner under the input
     *   - suffix   → optional unit ('%', 'RCOIN', 'USD', 'days')
     *   - step/min → numeric input bounds
     */
    public function groups(): array
    {
        return [
            'General' => [
                ['key' => 'rcoin_enabled',           'label' => 'Rcoin engine enabled',     'type' => 'boolean', 'help' => 'Master switch. When OFF no rewards are awarded, no redemptions are accepted.'],
                ['key' => 'rcoin_usd_rate',          'label' => 'USD value of 1 Rcoin',     'type' => 'float',   'help' => 'Conversion rate. 0.005 means 1 Rcoin = half a cent (200 Rcoin = $1).', 'suffix' => 'USD', 'step' => '0.0001', 'min' => '0'],
                ['key' => 'reward_reversal_enabled', 'label' => 'Reverse on refund',        'type' => 'boolean', 'help' => 'When a completed order is refunded, the cashback + referral bonus it awarded are debited back.'],
                ['key' => 'fraud_hold_enabled',      'label' => 'Hold rewards for review',  'type' => 'boolean', 'help' => 'Hold awarded Rcoin in pending state for the configured number of days before they become spendable.'],
                ['key' => 'fraud_hold_days',         'label' => 'Hold duration',            'type' => 'integer', 'help' => 'Days a reward is held before clearing. Only used when fraud hold is enabled.', 'suffix' => 'days', 'min' => '0'],
            ],
            'Cashback (Buyer)' => [
                ['key' => 'cashback_percentage',         'label' => 'Cashback %',              'type' => 'float',   'help' => 'Percentage of the order total awarded back to the buyer as Rcoin.', 'suffix' => '%', 'step' => '0.1', 'min' => '0'],
                ['key' => 'max_daily_reward_per_user',   'label' => 'Daily cap per user',      'type' => 'integer', 'help' => 'Maximum Rcoin one user can earn in 24 hours (cashback + referral combined). 0 = no cap.', 'suffix' => 'RCOIN', 'min' => '0'],
                ['key' => 'max_monthly_reward_per_user', 'label' => 'Monthly cap per user',    'type' => 'integer', 'help' => 'Maximum Rcoin one user can earn in a calendar month. 0 = no cap.', 'suffix' => 'RCOIN', 'min' => '0'],
            ],
            'Referrals' => [
                ['key' => 'referral_enabled',                    'label' => 'Referrals enabled',         'type' => 'boolean', 'help' => 'Master switch for the referral programme. Existing referrals stop earning when OFF.'],
                ['key' => 'referral_reward_percentage',          'label' => 'Referral reward %',         'type' => 'float',   'help' => 'Percentage of a referred customer\'s order total awarded to the referrer.', 'suffix' => '%', 'step' => '0.1', 'min' => '0'],
                ['key' => 'recurring_referral_rewards_enabled',  'label' => 'Recurring rewards',         'type' => 'boolean', 'help' => 'Reward the referrer for every order the referred user makes (not just the first).'],
                ['key' => 'max_referral_rewards_per_user',       'label' => 'Max payouts per referee',   'type' => 'integer', 'help' => 'Hard cap on how many orders from a single referred user can reward the referrer. 0 = unlimited.', 'min' => '0'],
                ['key' => 'max_referral_rewards_daily',          'label' => 'Daily cap (referrer)',      'type' => 'integer', 'help' => 'Maximum Rcoin a referrer can earn from all their referrals per day.', 'suffix' => 'RCOIN', 'min' => '0'],
                ['key' => 'max_referral_rewards_monthly',        'label' => 'Monthly cap (referrer)',    'type' => 'integer', 'help' => 'Maximum Rcoin a referrer can earn from all their referrals per month.', 'suffix' => 'RCOIN', 'min' => '0'],
            ],
            'Redemption (Checkout)' => [
                ['key' => 'redemption_enabled',         'label' => 'Allow Rcoin at checkout', 'type' => 'boolean', 'help' => 'Customers can apply their Rcoin balance against the order total. Disable to keep Rcoin earn-only.'],
                ['key' => 'redemption_min_rcoin',       'label' => 'Min Rcoin to redeem',     'type' => 'integer', 'help' => 'Below this balance the redemption toggle is hidden on checkout.', 'suffix' => 'RCOIN', 'min' => '0'],
                ['key' => 'redemption_max_percentage',  'label' => 'Max % of order',          'type' => 'float',   'help' => 'Cap on how much of the order total can be paid with Rcoin. 30 = customer must still pay 70% in cash.', 'suffix' => '%', 'step' => '0.5', 'min' => '0'],
            ],
            'Wallet Conversion (Instant)' => [
                ['key' => 'wallet_conversion_enabled', 'label' => 'Allow convert to wallet', 'type' => 'boolean', 'help' => 'Customers can convert Rcoin to their USD wallet balance instantly. No admin approval. Different from withdrawal which routes funds OUT.'],
                ['key' => 'wallet_conversion_min_usd', 'label' => 'Minimum conversion',      'type' => 'float',   'help' => 'Minimum USD value a single conversion must produce. Stops dust conversions.', 'suffix' => 'USD', 'step' => '0.50', 'min' => '0'],
            ],
            'Withdrawal' => [
                ['key' => 'withdrawal_enabled',           'label' => 'Allow withdrawals',           'type' => 'boolean', 'help' => 'Customers can convert Rcoin to a fiat wallet balance. Disable for earn-and-spend-only mode.'],
                ['key' => 'withdrawal_min_rcoin',         'label' => 'Min Rcoin to withdraw',       'type' => 'integer', 'help' => 'Floor on a single withdrawal request.', 'suffix' => 'RCOIN', 'min' => '0'],
                ['key' => 'withdrawal_minimum_usd',       'label' => 'Min USD value to withdraw',   'type' => 'float',   'help' => 'Belt-and-braces floor expressed in USD - protects against rate drift bypassing the Rcoin floor.', 'suffix' => 'USD', 'step' => '0.01', 'min' => '0'],
                ['key' => 'withdrawal_fee_percentage',    'label' => 'Withdrawal fee',              'type' => 'float',   'help' => 'Optional fee deducted from each withdrawal.', 'suffix' => '%', 'step' => '0.1', 'min' => '0'],
                ['key' => 'withdrawal_conversion_rate',   'label' => 'Withdrawal USD rate',         'type' => 'float',   'help' => 'Rcoin -> USD rate applied at withdrawal time. Usually matches the global Rcoin rate but can be set lower to incentivise on-site spending.', 'suffix' => 'USD', 'step' => '0.0001', 'min' => '0'],
            ],
            'Compliance & Security' => [
                ['key' => 'require_email_verified_for_checkout', 'label' => 'Require verified email at checkout', 'type' => 'boolean', 'help' => 'Block checkout until the buyer has clicked the email verification link. Soft-default (OFF) keeps the storefront friction-free; flip ON when fraud or chargebacks justify the extra step.'],
                ['key' => 'require_kyc_for_withdrawal',          'label' => 'Require KYC for withdrawals',        'type' => 'boolean', 'help' => 'Block Rcoin withdrawal requests until the customer has completed and been approved for identity verification. Flip ON when compliance / regulatory exposure justifies the friction.'],
            ],
        ];
    }

    public function mount(): void
    {
        // Prime the form with current DB values, falling back to the default
        // we'd expect from the seeder. Cast booleans so checkboxes bind cleanly.
        foreach ($this->groups() as $rows) {
            foreach ($rows as $row) {
                $raw = Setting::get($row['key']);
                $this->values[$row['key']] = $row['type'] === 'boolean' ? (bool) $raw : $raw;
            }
        }
    }

    /**
     * Save a single setting on blur / change. Cheap (single UPDATE +
     * cache::forget) and means there's no "have I unsaved changes?" footer.
     * The savedKey flash drives the inline "Saved" tick next to the field.
     */
    public function save(string $key): void
    {
        // Find the row's type from the schema (don't trust client).
        $type = null;
        foreach ($this->groups() as $rows) {
            foreach ($rows as $row) {
                if ($row['key'] === $key) { $type = $row['type']; break 2; }
            }
        }
        if ($type === null) { return; }

        $value = $this->values[$key] ?? null;
        $value = match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float'   => (float) $value,
            default   => $value,
        };

        // Keep the description in sync so a fresh seeder run doesn't blank it.
        $existing = Setting::where('key', $key)->first();
        Setting::set($key, $value, $type, $existing?->description);

        $this->values[$key] = $value;
        $this->savedKey = $key;
    }
}; ?>

<div class="w-full px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Rcoin Rewards</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Tune the cashback, referral and redemption rules. Changes save on blur and take effect immediately.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.content.rewards.analytics') }}" wire:navigate class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                View analytics
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                </svg>
            </a>
            <a href="/dashboard/rewards" target="_blank" class="inline-flex items-center gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-200 dark:hover:bg-[#26416b]">
                Customer view
            </a>
        </div>
    </header>

    {{-- Master kill-switch is rendered standalone at the top because it's
         the most impactful setting on the page. --}}
    @php $masterRow = ['key' => 'rcoin_enabled', 'label' => 'Rcoin engine', 'help' => 'When OFF no rewards are awarded, no redemptions accepted, no referrals counted. Storefront copy (the "earn X Rcoin" lines, the redeem toggle and the marketing page) auto-hides too. Existing balances stay spendable from the Rewards dashboard.']; @endphp
    <section class="mb-6 flex items-start justify-between gap-4 rounded-[12px] border-[1.5px] border-white bg-white p-5 shadow-sm shadow-zinc-900/[0.04] ring-2 ring-blue-500 dark:border-white dark:bg-[#1d3252] dark:ring-blue-400">
        <div>
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $masterRow['label'] }}</h2>
            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $masterRow['help'] }}</p>
        </div>
        <label class="inline-flex shrink-0 cursor-pointer items-center gap-2">
            <input type="checkbox" wire:model.live="values.rcoin_enabled" @change="$wire.save('rcoin_enabled')" class="h-5 w-5 rounded text-blue-600 focus:ring-blue-500">
            <span class="text-sm font-semibold" :class="$wire.values.rcoin_enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500'" x-text="$wire.values.rcoin_enabled ? 'On' : 'Off'"></span>
        </label>
    </section>

    @foreach ($this->groups() as $groupName => $rows)
        @if ($groupName === 'General') @continue @endif {{-- General's only field (rcoin_enabled) is the master switch above. --}}
        <section class="mb-5 overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            {{-- Header pill --}}
            <header class="mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:ring-blue-400">
                <h2 class="text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">{{ $groupName }}</h2>
            </header>
            <div class="divide-inset">
                @foreach ($rows as $row)
                    <div class="group relative mx-3 flex flex-col gap-3 px-5 py-4 transition-all hover:bg-blue-50 hover:rounded-[12px] sm:flex-row sm:items-center sm:justify-between dark:hover:bg-blue-600/15 dark:hover:ring-blue-400" wire:key="setting-{{ $row['key'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <label for="set-{{ $row['key'] }}" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $row['label'] }}</label>
                                @if ($savedKey === $row['key'])
                                    <span
                                        x-data="{ show: true }"
                                        x-init="setTimeout(() => show = false, 1500)"
                                        x-show="show"
                                        x-transition.opacity
                                        class="inline-flex items-center gap-1 rounded-[5px] bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30"
                                    >
                                        <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        Saved
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 max-w-xl text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $row['help'] }}</p>
                        </div>

                        <div class="shrink-0 sm:w-56">
                            @if ($row['type'] === 'boolean')
                                <label class="inline-flex cursor-pointer items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="set-{{ $row['key'] }}"
                                        wire:model.live="values.{{ $row['key'] }}"
                                        @change="$wire.save('{{ $row['key'] }}')"
                                        class="h-5 w-5 rounded text-blue-600 focus:ring-blue-500"
                                    >
                                    <span class="text-sm font-semibold" :class="$wire.values['{{ $row['key'] }}'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500'" x-text="$wire.values['{{ $row['key'] }}'] ? 'On' : 'Off'"></span>
                                </label>
                            @else
                                <div class="flex overflow-hidden rounded-[12px] border border-zinc-200 dark:border-zinc-700/60">
                                    <input
                                        type="number"
                                        id="set-{{ $row['key'] }}"
                                        wire:model="values.{{ $row['key'] }}"
                                        @change="$wire.save('{{ $row['key'] }}')"
                                        @if (isset($row['step'])) step="{{ $row['step'] }}" @endif
                                        @if (isset($row['min'])) min="{{ $row['min'] }}" @endif
                                        class="w-full bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 dark:bg-[#26416b] dark:text-white"
                                    >
                                    @if (! empty($row['suffix']))
                                        <span class="flex shrink-0 items-center bg-zinc-50 px-3 text-xs font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $row['suffix'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    {{-- Helper math chip - shows the rate's real-world meaning. --}}
    <aside class="mt-2 rounded-[12px] bg-blue-50 px-4 py-3 text-xs text-blue-800 ring-1 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        @php
            $rate = (float) ($values['rcoin_usd_rate'] ?? 0.005);
            $rcoinPerDollar = $rate > 0 ? (int) round(1 / $rate) : 0;
        @endphp
        At the current rate, <span class="font-bold">$1 = {{ number_format($rcoinPerDollar) }} Rcoin</span>.
        A {{ $values['cashback_percentage'] ?? 1 }}% cashback on a $20 order awards
        <span class="font-bold">{{ $rate > 0 ? (int) floor(20 * (((float) ($values['cashback_percentage'] ?? 1)) / 100) / $rate) : 0 }} Rcoin</span>.
    </aside>
</div>
